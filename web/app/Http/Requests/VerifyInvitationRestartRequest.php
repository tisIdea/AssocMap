<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyInvitationRestartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token_hash' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{56}\z/D'],
            'sign_out_current_account' => $this->session()->has('supabase_access_token') ? ['required', 'accepted'] : [],
            'auth_user_id' => ['prohibited'], 'email' => ['prohibited'], 'role_id' => ['prohibited'],
            'association_id' => ['prohibited'], 'invitation_id' => ['prohibited'], 'redirect_to' => ['prohibited']];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(redirect()->route('invitation.restart')
            ->withErrors(['restart' => 'Reopen the recovery email and confirm sign out if this browser is already signed in. If verification remains unavailable, contact an Administrator.']));
    }
}
