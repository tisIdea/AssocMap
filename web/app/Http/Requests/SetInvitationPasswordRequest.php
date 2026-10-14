<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class SetInvitationPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required', 'string', 'max:128'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(redirect()->route('invitation.password')
            ->withErrors($validator->errors()));
    }
}
