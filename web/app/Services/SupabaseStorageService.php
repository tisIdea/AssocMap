<?php

namespace App\Services;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupabaseStorageService
{
    private const BUCKET = 'member-application-photos';
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_JSON_BYTES = 16384;

    public function upload(string $path, string $contents, string $mimeType): array
    {
        $settings = $this->settings();
        if ($settings === null) return ['outcome' => 'failed', 'code' => 'storage_configuration_invalid'];
        if ($this->pathMimeType($path) !== $mimeType || $contents === '' || strlen($contents) > self::MAX_BYTES) {
            return ['outcome' => 'failed', 'code' => 'invalid_photo_upload'];
        }

        $deadline = hrtime(true) + $settings['timeout'] * 1000000000;
        try {
            // Never retry or overwrite: a lost response may follow successful object creation.
            $response = $this->request($settings)->withHeaders(['x-upsert' => 'false'])
                ->withBody($contents, $mimeType)->post($settings['base'].'/object/'.self::BUCKET.'/'.$path);
        } catch (ConnectionException | GuzzleException $exception) {
            return ['outcome' => 'unknown', 'code' => 'photo_upload_outcome_uncertain'];
        }

        $body = $this->contents($response, self::MAX_JSON_BYTES, $deadline);
        if ($response->successful() && $body !== null) {
            $result = json_decode($body, true);
            if (is_array($result) && ($result['Key'] ?? null) === self::BUCKET.'/'.$path) {
                return ['outcome' => 'uploaded', 'code' => 'photo_uploaded'];
            }
        }
        if ($response->clientError()) return ['outcome' => 'failed', 'code' => 'photo_upload_rejected'];

        return ['outcome' => 'unknown', 'code' => 'photo_upload_outcome_uncertain'];
    }

    public function download(string $path): array
    {
        $settings = $this->settings();
        $mimeType = $this->pathMimeType($path);
        if ($settings === null || $mimeType === null) return ['outcome' => 'unavailable'];

        $deadline = hrtime(true) + $settings['timeout'] * 1000000000;
        try {
            $response = $this->request($settings)->accept($mimeType)
                ->get($settings['base'].'/object/authenticated/'.self::BUCKET.'/'.$path);
        } catch (ConnectionException | GuzzleException $exception) {
            return ['outcome' => 'unavailable'];
        }

        $contents = $this->contents($response, $response->status() === 200 ? self::MAX_BYTES : self::MAX_JSON_BYTES, $deadline);
        if ($this->missingObject($response, $contents)) return ['outcome' => 'missing'];
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($response->status() !== 200 || $contents === null || $contents === '' || $contentType !== $mimeType) {
            return ['outcome' => 'unavailable'];
        }

        return ['outcome' => 'downloaded', 'contents' => $contents, 'mime_type' => $mimeType];
    }

    public function delete(string $path): array
    {
        $settings = $this->settings();
        if ($settings === null || $this->pathMimeType($path) === null) return ['outcome' => 'unavailable'];

        $deadline = hrtime(true) + $settings['timeout'] * 1000000000;
        try {
            $response = $this->request($settings)->asJson()
                ->delete($settings['base'].'/object/'.self::BUCKET, ['prefixes' => [$path]]);
        } catch (ConnectionException | GuzzleException $exception) {
            return ['outcome' => 'unavailable'];
        }

        $contents = $this->contents($response, self::MAX_JSON_BYTES, $deadline);
        if ($this->missingObject($response, $contents)) return ['outcome' => 'missing'];
        if ($response->status() === 200 && $contents !== null) {
            $removed = json_decode($contents);
            if (is_array($removed)) {
                if ($removed === []) return ['outcome' => 'missing'];
                if (count($removed) === 1 && $removed[0] instanceof \stdClass && ($removed[0]->name ?? null) === $path) {
                    return ['outcome' => 'deleted'];
                }
            }
        }

        return ['outcome' => 'unavailable'];
    }

    private function settings(): ?array
    {
        $config = config('services.supabase_storage', []);
        if (! is_array($config) || ($config['bucket'] ?? null) !== self::BUCKET) return null;
        $url = $config['url'] ?? null;
        $key = $config['secret_key'] ?? null;
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! is_string($key) || strlen($key) > 4096) return null;
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) return null;

        $headers = ['apikey' => $key];
        if (($config['key_type'] ?? null) === 'secret') {
            if (! preg_match('/\Asb_secret_[A-Za-z0-9_-]+\z/D', $key)) return null;
        } elseif (($config['key_type'] ?? null) === 'service_role') {
            if (! preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $key)) return null;
            $segments = explode('.', $key);
            $payload = json_decode(base64_decode(strtr($segments[1], '-_', '+/'), true) ?: '', true);
            if (! is_array($payload) || ($payload['role'] ?? null) !== 'service_role') return null;
            $headers['Authorization'] = 'Bearer '.$key;
        } else {
            return null;
        }

        $connectTimeout = filter_var($config['connect_timeout'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10]]);
        $timeout = filter_var($config['timeout'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 60]]);
        if ($connectTimeout === false || $timeout === false || $connectTimeout > $timeout) return null;

        return ['base' => rtrim($url, '/').'/storage/v1', 'headers' => $headers,
            'connect_timeout' => $connectTimeout, 'timeout' => $timeout];
    }

    private function request(array $settings): PendingRequest
    {
        // The streaming handler bounds connection and response headers together.
        return Http::withHeaders($settings['headers'])->acceptJson()
            ->connectTimeout($settings['connect_timeout'])->timeout($settings['connect_timeout'])
            ->withOptions(['allow_redirects' => false, 'verify' => true, 'cookies' => false, 'stream' => true,
                'read_timeout' => $settings['timeout']]);
    }

    private function contents(Response $response, int $limit, int|float $deadline): ?string
    {
        $body = $response->toPsrResponse()->getBody();
        try {
            $length = trim($response->header('Content-Length'));
            if ($length !== '' && (! ctype_digit($length) || (int) $length > $limit)) return null;
            $contents = '';
            while (! $body->eof()) {
                if (hrtime(true) > $deadline) return null;
                $chunk = $body->read(min(8192, $limit + 1 - strlen($contents)));
                if ($chunk === '' && ! $body->eof()) return null;
                $contents .= $chunk;
                if (strlen($contents) > $limit) return null;
            }
            if ($length !== '' && strlen($contents) !== (int) $length) return null;

            return hrtime(true) <= $deadline ? $contents : null;
        } catch (RuntimeException $exception) {
            return null;
        } finally {
            $body->close();
        }
    }

    private function missingObject(Response $response, ?string $contents): bool
    {
        if ($response->status() !== 404 || $contents === null) return false;
        $error = json_decode($contents, true);
        if (! is_array($error)) return false;
        if (($error['code'] ?? null) === 'NoSuchKey') return true;

        return ($error['error'] ?? null) === 'not_found' && ($error['message'] ?? null) === 'Object not found';
    }

    private function pathMimeType(string $path): ?string
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        if (! preg_match('~\Aphotos/'.$uuid.'/'.$uuid.'\.(jpg|png)\z~', $path, $matches)) return null;

        return $matches[1] === 'jpg' ? 'image/jpeg' : 'image/png';
    }
}
