<?php

namespace App\Http\Requests;

use App\Services\Auth\InvitationActivationContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['token_hash' => ['required', 'string', 'regex:/\A[a-fA-F0-9]{56}\z/D']];
        if ($this->hasSession() && $this->session()->has('supabase_access_token')) {
            $rules['sign_out_current_account'] = ['required', 'accepted'];
        }

        return $rules;
    }

    protected function failedValidation(Validator $validator): never
    {
        app(InvitationActivationContext::class)->forget($this);
        throw new HttpResponseException(redirect()->route('invitation.verify')
            ->withErrors(['invitation' => 'This invitation could not be verified. Open the invitation email again or contact the administrator.']));
    }
}
