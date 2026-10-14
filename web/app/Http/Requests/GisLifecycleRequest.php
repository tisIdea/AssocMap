<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GisLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->attributes->get('current_user');

        return $user?->canAccessApplication() && in_array($user->role->code, ['system_admin', 'field_officer'], true);
    }

    public function rules(): array
    {
        return ['version' => ['required', 'string', 'max:64']];
    }
}
