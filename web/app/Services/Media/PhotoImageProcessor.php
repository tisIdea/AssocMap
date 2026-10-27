<?php

namespace App\Services\Media;

use ErrorException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PhotoImageProcessor
{
    public function fingerprint(UploadedFile $file): string
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > PhotoRules::MAX_BYTES) {
            throw $this->invalid();
        }
        $hash = hash_file('sha256', $file->getPathname());
        if (! is_string($hash)) {
            throw $this->invalid();
        }

        return $hash;
    }

    /** Returned temporary streams belong to the caller and must be closed. */
    public function process(UploadedFile $file): array
    {
        $this->fingerprint($file);
        $streams = [];
        $source = null;
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException('Image processing failed.', 0, $severity);
        }, E_WARNING | E_NOTICE);
        try {
            $info = getimagesize($file->getPathname());
            if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
                || $info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) > PhotoRules::MAX_DIMENSION
                || $info[0] * $info[1] > PhotoRules::MAX_PIXELS) {
                throw $this->invalid();
            }
            $jpeg = $info[2] === IMAGETYPE_JPEG;
            if ($file->getMimeType() !== ($jpeg ? 'image/jpeg' : 'image/png')
                || ! in_array(strtolower($file->getClientOriginalExtension()), $jpeg ? ['jpg', 'jpeg'] : ['png'], true)) {
                throw $this->invalid();
            }
            $this->memoryBudget($info[0], $info[1]);
            $source = $jpeg ? imagecreatefromjpeg($file->getPathname()) : imagecreatefrompng($file->getPathname());
            if (! $source || imagesx($source) !== $info[0] || imagesy($source) !== $info[1]) {
                throw $this->invalid();
            }
            if ($jpeg && function_exists('exif_read_data')) {
                try {
                    $exif = exif_read_data($file->getPathname(), 'IFD0', true, false);
                } catch (ErrorException) {
                    $exif = false;
                }
                $source = $this->orient($source, (int) ($exif['IFD0']['Orientation'] ?? 1));
            }
            $result = ['image_format' => $jpeg ? 'jpg' : 'png'];
            foreach (['display' => PhotoRules::DISPLAY_EDGE, 'thumbnail' => PhotoRules::THUMBNAIL_EDGE] as $name => $edge) {
                $scale = min(1, $edge / max(imagesx($source), imagesy($source)));
                $width = max(1, (int) round(imagesx($source) * $scale));
                $height = max(1, (int) round(imagesy($source) * $scale));
                $image = imagecreatetruecolor($width, $height);
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                $stream = tmpfile();
                if ($stream === false) {
                    throw $this->invalid();
                }
                $streams[$name] = $stream;
                $ok = $jpeg ? imagejpeg($image, $stream, $name === 'display' ? 85 : 80) : imagepng($image, $stream, 6);
                unset($image);
                $bytes = fstat($stream)['size'];
                if (! $ok || $bytes < 1 || $bytes > PhotoRules::MAX_BYTES) {
                    throw $this->invalid();
                }
                rewind($stream);
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                $result += [$name.'_width' => $width, $name.'_height' => $height,
                    $name.'_bytes' => $bytes, $name.'_sha256' => hash_final($hash)];
                rewind($stream);
            }

            return ['metadata' => $result, 'streams' => $streams];
        } catch (Throwable) {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            throw $this->invalid();
        } finally {
            unset($source);
            restore_error_handler();
        }
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [4], true) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
        };

        return $angle === 0 ? $image : imagerotate($image, $angle, 0);
    }

    private function memoryBudget(int $width, int $height): void
    {
        $estimate = $width * $height * 8 + min($width * $height, PhotoRules::DISPLAY_EDGE ** 2) * 8 + 16777216;
        $limit = trim(ini_get('memory_limit'));
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
        };
        if ($estimate > 134217728 || ($bytes > 0 && memory_get_usage(true) + $estimate > $bytes)) {
            throw $this->invalid();
        }
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['photo' => 'Use an uncorrupted JPEG or PNG with a matching extension, at most 5 MiB, 8192 pixels per edge and 12 million pixels.']);
    }
}
