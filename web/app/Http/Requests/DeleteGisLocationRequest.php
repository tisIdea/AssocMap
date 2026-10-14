<?php

namespace App\Http\Requests;

class DeleteGisLocationRequest extends GisLifecycleRequest
{
    public function rules(): array
    {
        return parent::rules() + ['confirmation' => ['required', 'accepted']];
    }
}
