<?php

namespace App\Services;

use App\Exceptions\PrivateMediaStorageException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use Throwable;

/** Keeps the media_private disk private and strips provider details from failures. */
final class PrivateMediaAdapter implements FilesystemAdapter
{
    public function __construct(private FilesystemAdapter $adapter) {}

    public function fileExists(string $path): bool
    {
        return $this->attempt('exists_failed', fn () => $this->adapter->fileExists($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->attempt('exists_failed', fn () => $this->adapter->directoryExists($path));
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $config = $this->privateOptions($config);
        $this->attempt('write_unconfirmed', fn () => $this->adapter->write($path, $contents, $config));
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $config = $this->privateOptions($config);
        $this->attempt('write_unconfirmed', fn () => $this->adapter->writeStream($path, $contents, $config));
    }

    public function read(string $path): string
    {
        return $this->attempt('read_failed', fn () => $this->adapter->read($path));
    }

    public function readStream(string $path)
    {
        return $this->attempt('read_failed', fn () => $this->adapter->readStream($path));
    }

    public function delete(string $path): void
    {
        $this->attempt('delete_unconfirmed', fn () => $this->adapter->delete($path));
    }

    public function deleteDirectory(string $path): void
    {
        $this->attempt('delete_unconfirmed', fn () => $this->adapter->deleteDirectory($path));
    }

    public function createDirectory(string $path, Config $config): void
    {
        $config = $this->privateOptions($config);
        $this->attempt('write_unconfirmed', fn () => $this->adapter->createDirectory($path, $config));
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if ($visibility !== 'private') {
            throw new PrivateMediaStorageException('options_rejected');
        }

        throw new PrivateMediaStorageException('operation_unsupported');
    }

    public function visibility(string $path): FileAttributes
    {
        throw new PrivateMediaStorageException('operation_unsupported');
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->attempt('metadata_failed', fn () => $this->adapter->mimeType($path));
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->attempt('metadata_failed', fn () => $this->adapter->lastModified($path));
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->attempt('metadata_failed', fn () => $this->adapter->fileSize($path));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            yield from $this->adapter->listContents($path, $deep);
        } catch (Throwable) {
            throw new PrivateMediaStorageException('list_failed');
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $config = $this->privateOptions($config);
        $this->attempt('move_unconfirmed', fn () => $this->adapter->move($source, $destination, $config));
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $config = $this->privateOptions($config);
        $this->attempt('copy_unconfirmed', fn () => $this->adapter->copy($source, $destination, $config));
    }

    private function privateOptions(Config $config): Config
    {
        $allowed = ['visibility', 'directory_visibility', 'retain_visibility', 'ContentType',
            'CacheControl', 'ContentDisposition', 'ContentEncoding', 'Metadata'];
        if (array_diff(array_keys($config->toArray()), $allowed) !== []
            || $config->get('visibility', 'private') !== 'private'
            || $config->get('directory_visibility', 'private') !== 'private') {
            throw new PrivateMediaStorageException('options_rejected');
        }

        return $config->extend(['visibility' => 'private', 'directory_visibility' => 'private', 'retain_visibility' => false]);
    }

    private function attempt(string $reason, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            // Never chain/log SDK exceptions: they may contain signed URLs, keys or bodies.
            throw new PrivateMediaStorageException($reason);
        }
    }
}
