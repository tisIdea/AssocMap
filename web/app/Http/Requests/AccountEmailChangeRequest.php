<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class AccountEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (config('services.supabase.account_email_change_enabled') !== true) {
            return false;
        }
        if (! $this->routeIs('admin.users.email-change.*')) {
            return true;
        }
        $actor = $this->attributes->get('current_user');

        return $actor?->role?->code === 'system_admin' && $actor->canAccessApplication();
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('admin.users.email-change.*') && is_string($this->input('new_email'))) {
            $this->merge(['new_email' => mb_strtolower(trim($this->input('new_email')))]);
        }
    }

    public function rules(): array
    {
        $rules = match (true) {
            $this->routeIs('admin.users.email-change.review', 'admin.users.email-change.store') => [
                'new_email' => ['required', 'string', 'email:rfc', 'max:254'], 'same_holder' => ['required', 'accepted'],
            ] + ($this->routeIs('admin.users.email-change.store') ? [
                'confirm_change' => ['required', 'accepted'], 'review_hash' => ['required', 'regex:/\A[a-f0-9]{64}\z/D'],
            ] : []),
            $this->routeIs('account-email-change.verify-old', 'account-email-change.confirm') => [
                'token_hash' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{56}\z/D'],
            ],
            $this->routeIs('account-email-change.complete') => [
                'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
                'password_confirmation' => ['required', 'string', 'max:128'],
            ],
            default => ['confirm_change' => ['required', 'accepted']],
        };
        if (! $this->routeIs('admin.users.email-change.review', 'admin.users.email-change.store')) {
            $rules['new_email'] = ['prohibited'];
        }

        return $rules + array_fill_keys(['auth_user_id', 'user_id', 'role_id', 'association_id', 'email', 'old_email',
            'access_token', 'refresh_token', 'request_id', 'is_active'], ['prohibited']);
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($this->routeIs('admin.users.email-change.*')) {
            parent::failedValidation($validator);
        }
        throw new HttpResponseException(redirect()->route('account-email-change.result')
            ->withErrors(['email_change' => 'Review the form. Use the original verification link or matching strong passwords. No submitted secret was saved.']));
    }
}
