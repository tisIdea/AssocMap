<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MonitoringIncomeConflictException extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'Newer Income changes exist. Your entries have not been saved. Keep this draft and open the latest saved Income in a new tab to review it before editing again.');
    }
}
