<?php

namespace App\Http\Requests;

use App\Models\Association;
use App\Models\AssociationAccount;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->attributes->get('current_user');

        return $user?->canAccessApplication() && $user->role->code === 'system_admin';
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['email', 'display_name', 'association_id'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $values[$field] = $field === 'email' ? mb_strtolower(trim($value)) : trim($value);
                if ($field === 'association_id') $values[$field] = $values[$field] === '' ? null : strtolower($values[$field]);
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'min:4', 'max:255', 'not_regex:/\s/u'],
            'display_name' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query
                ->where('is_active', true)->whereIn('code', ['field_officer', 'association_account']))],
            'association_id' => ['nullable', 'uuid'],
            'invited_by' => ['prohibited'],
            'auth_user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'is_active' => ['prohibited'],
            'password' => ['prohibited'],
            'redirect_to' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) return;
            $role = Role::find($this->input('role_id'));
            $associationId = $this->input('association_id');
            if ($role?->code === 'field_officer') {
                if ($associationId !== null) $validator->errors()->add('association_id', 'Field Officer invitations do not assign or replace an association officer.');
                return;
            }
            if (! $associationId || ! Association::whereKey($associationId)->where('status', 'active')->whereNull('archived_at')->exists()) {
                $validator->errors()->add('association_id', 'Select an active, unarchived association.');
            } elseif (AssociationAccount::where('association_id', $associationId)->exists()) {
                $validator->errors()->add('association_id', 'This association already has an account link. Reconcile that link before inviting another account.');
            }
        }];
    }
}
