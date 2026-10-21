<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevokeUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    public function rules(): array
    {
        return [
            'confirm_revocation' => ['required', 'accepted'],
            'is_active' => ['prohibited'], 'role_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'auth_user_id' => ['prohibited'], 'association_id' => ['prohibited'], 'email' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_revocation.required' => 'Confirm that this account access should be revoked.',
            'confirm_revocation.accepted' => 'Confirm that this account access should be revoked.',
        ];
    }
}
