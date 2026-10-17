<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MonitoringMaterialsConflictException extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'Another authorized user saved newer Materials information. Your entries have not been saved. Keep this draft and open the latest saved Materials in a new tab before editing again.');
    }
}
