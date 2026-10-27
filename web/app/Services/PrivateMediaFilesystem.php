<?php

namespace App\Services;

use App\Exceptions\PrivateMediaStorageException;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use Throwable;

final class PrivateMediaFilesystem extends Filesystem
{
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return new DirectoryListing($this->safeListing($location, $deep));
    }

    private function safeListing(string $location, bool $deep): iterable
    {
        try {
            yield from parent::listContents($location, $deep);
        } catch (Throwable) {
            // Flysystem adds the requested prefix to its own listing exception.
            throw new PrivateMediaStorageException('list_failed');
        }
    }
}
