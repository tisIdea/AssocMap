<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFieldOfficerAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('association_ids');
        if (is_array($ids)) {
            $this->merge(['association_ids' => array_map(fn ($id) => is_string($id) ? strtolower(trim($id)) : $id, $ids)]);
        }
    }

    public function rules(): array
    {
        return [
            'association_ids' => ['required', 'array', 'min:1', 'max:50'],
            'association_ids.*' => ['required', 'uuid', 'distinct'],
            'expected_officers' => ['required', 'array', 'max:50'],
            'expected_officers.*' => ['required', 'uuid'],
            'confirm_reassignment' => ['accepted'],
            'role_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'primary_field_officer_id' => ['prohibited'], 'updated_by' => ['prohibited'],
        ];
    }
}
