<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use League\Flysystem\FilesystemException;
use RuntimeException;

final class PrivateMediaStorageException extends RuntimeException implements FilesystemException, ShouldntReport
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Private media storage is unavailable ('.$reason.').');
    }
}
