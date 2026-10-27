<?php

namespace App\Providers;

use App\Exceptions\PrivateMediaStorageException;
use App\Services\PrivateMediaAdapter;
use App\Services\PrivateMediaFilesystem;
use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class PrivateMediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(FilesystemManager::class)->extend('media_private_s3', function ($app, array $config) {
            $settings = $this->settings($config);

            try {
                $s3 = $app->make(FilesystemManager::class)->createS3Driver($settings);
                // Private buckets use default object permissions; R2 rejects explicit ACL headers.
                $s3->getClient()->getHandlerList()->appendInit(Middleware::mapCommand(static function (CommandInterface $command) {
                    unset($command['ACL']);

                    return $command;
                }), 'media_private.no_acl');
                $adapter = new PrivateMediaAdapter($s3->getAdapter());

                return new FilesystemAdapter(new PrivateMediaFilesystem($adapter, [
                    'visibility' => 'private',
                    'directory_visibility' => 'private',
                    'retain_visibility' => false,
                ]), $adapter, ['throw' => true, 'report' => false]);
            } catch (Throwable) {
                throw new PrivateMediaStorageException('configuration_invalid');
            }
        });
    }

    private function settings(array $config): array
    {
        foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $field) {
            $value = $config[$field] ?? null;
            if (! is_string($value) || trim($value) !== $value || $value === '' || strlen($value) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new PrivateMediaStorageException('configuration_invalid');
            }
        }

        foreach (['connect_timeout', 'timeout'] as $field) {
            if (! is_int($config[$field] ?? null) && ! is_string($config[$field] ?? null)) {
                throw new PrivateMediaStorageException('configuration_invalid');
            }
        }

        $endpoint = parse_url($config['endpoint']);
        $bucket = $config['bucket'];
        $connectTimeout = filter_var($config['connect_timeout'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10]]);
        $timeout = filter_var($config['timeout'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 120]]);

        if (! filter_var($config['endpoint'], FILTER_VALIDATE_URL) || ($endpoint['scheme'] ?? null) !== 'https'
            || empty($endpoint['host']) || isset($endpoint['user']) || isset($endpoint['pass'])
            || isset($endpoint['query']) || isset($endpoint['fragment'])
            || ! preg_match('/\A[a-zA-Z0-9-]{1,63}\z/', $config['region'])
            || ! preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/', $bucket)
            || str_contains($bucket, '..') || str_contains($bucket, '.-') || str_contains($bucket, '-.')
            || filter_var($bucket, FILTER_VALIDATE_IP)
            || ! is_bool($config['use_path_style_endpoint'] ?? null)
            || ($config['visibility'] ?? null) !== 'private' || ($config['directory_visibility'] ?? null) !== 'private'
            || ($config['throw'] ?? null) !== true || ($config['report'] ?? null) !== false
            || $connectTimeout === false || $timeout === false || $connectTimeout > $timeout) {
            throw new PrivateMediaStorageException('configuration_invalid');
        }

        // Explicit credentials prevent SDK profile/instance-metadata credential discovery.
        return [
            'driver' => 's3',
            'key' => $config['key'],
            'secret' => $config['secret'],
            'region' => $config['region'],
            'bucket' => $bucket,
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => $config['use_path_style_endpoint'],
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'stream_reads' => true,
            'retries' => 0,
            'http' => [
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
                'read_timeout' => $timeout,
                'verify' => true,
                'allow_redirects' => false,
            ],
            'throw' => true,
            'report' => false,
        ];
    }
}
