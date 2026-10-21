<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class PasswordRecoveryRequest extends FormRequest
{
    public const COOLDOWN_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('password.resend')) {
            $confirmation = $this->session()->get('recovery_request');
            $this->merge(['email' => is_array($confirmation) && ($confirmation['expires_at'] ?? 0) > now()->timestamp
                ? ($confirmation['email'] ?? null) : null]);
        }
        if ($this->routeIs('password.request', 'password.resend') && is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return match (true) {
            $this->routeIs('password.request', 'password.resend') => ['email' => ['required', 'string', 'email', 'max:254']],
            $this->routeIs('password.verify') => [
                'token_hash' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{56}\z/D'],
                'sign_out_current_account' => $this->session()->has('supabase_access_token') ? ['required', 'accepted'] : [],
            ],
            default => ['password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
                'password_confirmation' => ['required', 'string', 'max:128']],
        };
    }

    protected function failedValidation(Validator $validator): never
    {
        $this->session()->forget(['recovery_completed', 'recovery_requested']);
        if ($this->routeIs('password.resend')) {
            $this->session()->forget('recovery_request');
            throw new HttpResponseException(redirect()->route('password.forgot')
                ->withErrors(['recovery' => 'This recovery request has expired. Enter your email to request another link.']));
        }
        if ($this->routeIs('password.verify')) {
            throw new HttpResponseException(redirect()->route('password.recovery')
                ->withErrors(['recovery' => $validator->errors()->has('sign_out_current_account')
                    ? 'Your current account is still signed in. Reopen the recovery email and confirm sign out to continue.'
                    : 'No valid recovery token was submitted. Reopen the recovery email link.']));
        }
        if ($this->routeIs('password.request')) $this->session()->forget('recovery_request');
        throw new HttpResponseException(redirect()->route($this->routeIs('password.request') ? 'password.forgot' : 'password.reset')
            ->withErrors($validator->errors()));
    }
}
