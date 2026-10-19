<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->attributes->get('current_user')?->role?->code;

        return match (true) {
            $this->routeIs('admin.members.index') => $role === 'system_admin',
            $this->routeIs('field-officer.members.index', 'field-officer.member-applications.index') => $role === 'field_officer',
            $this->routeIs('association.member-applications.index') => $role === 'association_account',
            default => false,
        };
    }

    protected function getRedirectUrl()
    {
        return route($this->route()->getName());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) $this->merge(['q' => trim($this->query('q'))]);
        if (is_string($this->query('association'))) $this->merge(['association' => strtolower($this->query('association'))]);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in($this->applicationRegister()
                ? ['pending', 'approved', 'rejected'] : ['active', 'inactive', 'archived'])],
            'association' => $this->routeIs('association.*') ? ['prohibited'] : ['nullable', 'uuid'],
            'sort' => ['nullable', 'string', Rule::in(['az', 'za', 'newest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function filters(): array
    {
        return [
            'q' => $this->validated('q') ?? '',
            'status' => $this->validated('status') ?? ($this->routeIs('field-officer.*')
                ? ($this->applicationRegister() ? 'pending' : 'active') : ''),
            'association' => $this->validated('association') ?? '',
            'sort' => $this->validated('sort') ?? ($this->applicationRegister() ? 'newest' : 'az'),
        ];
    }

    private function applicationRegister(): bool
    {
        return $this->routeIs('*.member-applications.index');
    }
}
