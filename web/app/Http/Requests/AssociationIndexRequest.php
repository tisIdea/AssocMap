<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AssociationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication()
            && in_array($actor->role?->code, ['system_admin', 'field_officer'], true)
            && (!$this->filled('editor') || $actor->role->code === 'system_admin');
    }

    protected function getRedirectUrl()
    {
        $prefix = $this->attributes->get('current_user')?->role?->code === 'system_admin' ? 'admin' : 'field-officer';

        return route($prefix.'.'.($this->routeIs('*.association-officers.index') ? 'association-officers.index' : 'associations.index'));
    }

    protected function prepareForValidation(): void
    {
        foreach (['q', 'municipality', 'barangay', 'field_officer'] as $key) {
            if (is_string($this->query($key))) $this->merge([$key => trim($this->query($key))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['all', 'active', 'inactive', 'archived'])],
            'municipality' => ['nullable', 'string', 'max:150'],
            'barangay' => ['nullable', 'string', 'max:150'],
            'field_officer' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', Rule::in(['az', 'za', 'newest', 'oldest', 'municipality', 'barangay', 'field_officer', 'status'])],
            'section' => ['nullable', 'string', Rule::in(['members', 'projects', 'trainings', 'activities', 'registrations'])],
            'origin' => ['nullable', 'string', Rule::in(['register', 'officers'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'related_page' => ['nullable', 'integer', 'min:1'],
            'editor' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value !== 'create' && !Str::isUuid($value)) $fail('Select a valid association editor.');
            }],
        ];
    }

    public function filters(bool $officers = false): array
    {
        return [
            'q' => $this->validated('q') ?? '',
            'status' => $this->validated('status') ?? ($officers ? 'all' : ''),
            'municipality' => $this->validated('municipality') ?? '',
            'barangay' => $this->validated('barangay') ?? '',
            'field_officer' => $this->validated('field_officer') ?? '',
            'sort' => $this->validated('sort') ?? 'az',
        ];
    }
}
