<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class AccountReactivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->routeIs('admin.users.reactivation.store')) {
            return true;
        }
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    public function rules(): array
    {
        $rules = match (true) {
            $this->routeIs('admin.users.reactivation.store') => [
                'confirm_reactivation' => ['required', 'accepted'], 'confirm_relationships' => ['required', 'accepted'],
                'review_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            ],
            $this->routeIs('account-reactivation.verify') => [
                'token_hash' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{56}\z/D'],
                'sign_out_current_account' => $this->session()->has('supabase_access_token') ? ['required', 'accepted'] : [],
            ],
            default => ['password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
                'password_confirmation' => ['required', 'string', 'max:128']],
        };

        return $rules + array_fill_keys(['is_active', 'user_id', 'auth_user_id', 'email', 'role_id', 'association_id', 'reactivation_id'], ['prohibited']);
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($this->routeIs('admin.users.reactivation.store')) {
            parent::failedValidation($validator);
        }
        $url = $this->routeIs('account-reactivation.verify')
            ? route('account-reactivation.callback', $this->route('reference')) : route('account-reactivation.password');
        throw new HttpResponseException(redirect($url)->withErrors(['reactivation' => 'Review the form. Verification requires the original email link and password setup requires matching strong passwords.']));
    }
}
