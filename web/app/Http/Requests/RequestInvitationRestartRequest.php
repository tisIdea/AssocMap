<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RequestInvitationRestartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255', 'not_regex:/\s/u'],
            'user_id' => ['prohibited'], 'auth_user_id' => ['prohibited'], 'role_id' => ['prohibited'],
            'association_id' => ['prohibited'], 'invitation_id' => ['prohibited'], 'redirect_to' => ['prohibited']];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(redirect()->route('invitation.restart')
            ->withErrors(['restart' => 'Enter a valid invitation email and submit this form again.']));
    }
}
