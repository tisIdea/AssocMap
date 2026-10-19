<?php

namespace App\Services;

use App\Exceptions\MemberApplicationPhotoUnavailableException;
use ErrorException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use ValueError;

class MemberApplicationPhotoService
{
    private const DISK = 'member_application_photos';
    private const MAX_BYTES = 2 * 1024 * 1024;
    private mixed $transport;

    public function __construct(private SupabaseStorageService $remote)
    {
        $this->transport = config('services.supabase_storage.photo_transport', 'local');
    }

    public function store(UploadedFile $photo, string $applicationId): string
    {
        if (! Str::isUuid($applicationId)) throw new InvalidArgumentException('A valid application identifier is required.');
        $applicationId = strtolower($applicationId);
        [$contents, $extension] = $this->encode($photo);
        $path = 'photos/'.$applicationId.'/'.Str::uuid().'.'.$extension;

        if ($this->transport() === 'supabase') {
            $result = $this->remote->upload($path, $contents, $extension === 'jpg' ? 'image/jpeg' : 'image/png');
            if ($result['outcome'] === 'uploaded') return $path;

            Log::warning('Member application photo upload requires attention.', [
                'application_id' => $applicationId,
                'code' => $result['code'],
            ]);
            // Do not delete an unconfirmed upload or an object reported as already existing.
            throw new MemberApplicationPhotoUnavailableException($result['outcome'] === 'unknown'
                ? 'The photo upload could not be confirmed. No application was submitted. Wait before retrying; contact the administrator if this continues.'
                : 'The photo could not be stored. No application was submitted. Try again later or contact the administrator.');
        }

        try {
            $written = Storage::disk(self::DISK)->put($path, $contents, 'private');
        } catch (FilesystemException $exception) {
            $written = false;
        }

        if (! $written) {
            $this->discard($path, $applicationId);
            throw ValidationException::withMessages([
                'applicant_photo' => 'The photo could not be stored. Reselect it and retry. If this continues, contact the administrator.',
            ]);
        }

        return $path;
    }

    public function discard(string $path, string $applicationId): void
    {
        if ($this->mimeTypeFor($path, $applicationId) === null) return;

        try {
            if ($this->transport() === 'supabase') {
                $result = $this->remote->delete($path);
                if (in_array($result['outcome'], ['deleted', 'missing'], true)) return;
            } elseif (Storage::disk(self::DISK)->delete($path)) {
                return;
            }
        } catch (FilesystemException | MemberApplicationPhotoUnavailableException $exception) {
            // Preserve the original submission error if cleanup fails.
        }

        Log::warning('Member application photo cleanup requires reconciliation.', [
            'application_id' => $applicationId,
            'code' => 'photo_cleanup_failed',
        ]);
    }

    public function open(string $applicationId, ?string $path): ?array
    {
        $mimeType = $this->mimeTypeFor($path, $applicationId);
        if ($mimeType === null) return null;

        if ($this->transport() === 'supabase') {
            $photo = $this->remote->download($path);
            if ($photo['outcome'] === 'missing') return null;
            if ($photo['outcome'] !== 'downloaded') throw new MemberApplicationPhotoUnavailableException;

            $stream = null;
            try {
                $stream = fopen('php://temp', 'w+b');
                if (! is_resource($stream) || fwrite($stream, $photo['contents']) !== strlen($photo['contents']) || ! rewind($stream)) {
                    if (is_resource($stream)) fclose($stream);
                    throw new MemberApplicationPhotoUnavailableException;
                }
            } catch (ErrorException $exception) {
                if (is_resource($stream)) fclose($stream);
                throw new MemberApplicationPhotoUnavailableException;
            }

            return ['stream' => $stream, 'mime_type' => $mimeType];
        }

        try {
            $disk = Storage::disk(self::DISK);
            if (! $disk->exists($path)) return null;
            $stream = $disk->readStream($path);
        } catch (FilesystemException $exception) {
            throw new MemberApplicationPhotoUnavailableException;
        }

        if (! is_resource($stream)) throw new MemberApplicationPhotoUnavailableException;

        return ['stream' => $stream, 'mime_type' => $mimeType];
    }

    private function transport(): string
    {
        if (! in_array($this->transport, ['local', 'supabase'], true)) throw new MemberApplicationPhotoUnavailableException;

        return $this->transport;
    }

    public function mimeTypeFor(?string $path, string $applicationId): ?string
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        if ($path === null || ! preg_match('~\Aphotos/('.$uuid.')/'.$uuid.'\.(jpg|png)\z~', $path, $matches)
            || $matches[1] !== strtolower($applicationId)) return null;

        return $matches[2] === 'jpg' ? 'image/jpeg' : 'image/png';
    }

    private function encode(UploadedFile $photo): array
    {
        foreach (['imagecreatefromstring', 'imagejpeg', 'imagepng', 'imagesx', 'imagesy',
            'imagealphablending', 'imagesavealpha', 'imagedestroy'] as $function) {
            if (! function_exists($function)) {
                throw ValidationException::withMessages([
                    'applicant_photo' => 'Photo processing is unavailable. Contact the administrator to enable PHP GD with JPEG and PNG support.',
                ]);
            }
        }

        $image = null;
        $bufferLevel = ob_get_level();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_WARNING | E_NOTICE);

        try {
            if (! $photo->isValid() || $photo->getSize() > self::MAX_BYTES) {
                throw $this->invalidPhoto();
            }

            $info = getimagesize($photo->getPathname());
            if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048) {
                throw $this->invalidPhoto();
            }

            $mimeType = $photo->getMimeType();
            $originalExtension = strtolower($photo->getClientOriginalExtension());
            $isJpeg = $info[2] === IMAGETYPE_JPEG && $mimeType === 'image/jpeg'
                && in_array($originalExtension, ['jpg', 'jpeg'], true);
            $isPng = $info[2] === IMAGETYPE_PNG && $mimeType === 'image/png' && $originalExtension === 'png';
            if (! $isJpeg && ! $isPng) throw $this->invalidPhoto();

            $image = imagecreatefromstring($photo->getContent());
            if ($image === false || imagesx($image) !== $info[0] || imagesy($image) !== $info[1]) {
                throw $this->invalidPhoto();
            }

            // Re-encode decoded pixels; do not retain the original metadata or trailing payload.
            if ($isPng) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }
            ob_start();
            $encoded = $isJpeg ? imagejpeg($image, null, 90) : imagepng($image, null, 6);
            $contents = ob_get_contents();
            if (! $encoded || ! is_string($contents) || $contents === '' || strlen($contents) > self::MAX_BYTES) {
                throw $this->invalidPhoto();
            }

            return [$contents, $isJpeg ? 'jpg' : 'png'];
        } catch (ErrorException | FileException | ValueError $exception) {
            throw $this->invalidPhoto();
        } finally {
            while (ob_get_level() > $bufferLevel) ob_end_clean();
            restore_error_handler();
            if ($image !== null && $image !== false) imagedestroy($image);
        }
    }

    private function invalidPhoto(): ValidationException
    {
        return ValidationException::withMessages([
            'applicant_photo' => 'Use an uncorrupted JPEG or PNG photo with a matching filename extension, at most 2 MiB and 2048 by 2048 pixels. The processed photo must also fit the size limit.',
        ]);
    }
}
