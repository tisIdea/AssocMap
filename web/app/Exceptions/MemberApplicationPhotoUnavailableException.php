<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class MemberApplicationPhotoUnavailableException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'Photo storage is temporarily unavailable. Please try again later.')
    {
        parent::__construct($message);
    }
}
