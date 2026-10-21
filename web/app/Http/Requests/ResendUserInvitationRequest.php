<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResendUserInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    public function rules(): array
    {
        return [
            'confirm_resend' => ['required', 'accepted'],
            'email' => ['prohibited'], 'role_id' => ['prohibited'], 'association_id' => ['prohibited'],
            'auth_user_id' => ['prohibited'], 'user_id' => ['prohibited'], 'redirect_to' => ['prohibited'],
        ];
    }
}
