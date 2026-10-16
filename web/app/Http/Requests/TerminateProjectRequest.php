<?php

namespace App\Http\Requests;

class TerminateProjectRequest extends CloseProjectRequest
{
    protected const CONFIRMATION_FIELD = 'termination_confirmation';
    protected const CONFIRMATION_WORD = 'TERMINATE';

    protected $errorBag = 'projectTermination';
}
