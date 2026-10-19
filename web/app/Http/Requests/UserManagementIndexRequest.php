<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserManagementIndexRequest extends FormRequest
{
    protected $redirectRoute = 'admin.users.index';

    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) {
            $this->merge(['q' => trim($this->query('q'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', Rule::in(['system_admin', 'field_officer', 'association_account'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', 'string', Rule::in(['name', 'name_desc', 'newest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'assigned_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        $values = $this->validated();

        return [
            'q' => $values['q'] ?? '',
            'role' => $values['role'] ?? '',
            'status' => $values['status'] ?? '',
            'sort' => $values['sort'] ?? 'name',
        ];
    }
}
