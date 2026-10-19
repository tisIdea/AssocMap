<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProgramDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('current_user')?->role?->code
            === ($this->routeIs('admin.*') ? 'system_admin' : 'field_officer');
    }

    protected function getRedirectUrl()
    {
        return route($this->route()->getName());
    }

    protected function prepareForValidation(): void
    {
        foreach (['q', 'association', 'term'] as $key) {
            if (is_string($this->query($key))) $this->merge([$key => trim($this->query($key))]);
        }
    }

    public function rules(): array
    {
        $statuses = match (true) {
            $this->routeIs('*.projects.index') => ['planned', 'ongoing', 'suspended', 'cancelled', 'completed', 'terminated', 'archived'],
            $this->routeIs('*.program-activities.index') => ['planned', 'ongoing', 'completed', 'cancelled', 'archived'],
            default => ['scheduled', 'completed', 'cancelled', 'archived'],
        };
        $lookups = match (true) {
            $this->routeIs('*.projects.index') => ['associations'],
            $this->routeIs('*.program-activities.index') => ['associations', 'projects', 'activities'],
            default => ['associations', 'projects', 'members', 'training_choices'],
        };

        return [
            'q' => ['nullable', 'string', 'max:120'],
            'association' => ['nullable', 'string', 'max:150'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'status' => ['nullable', 'string', Rule::in($statuses)],
            'sort' => ['nullable', 'string', Rule::in(['year', 'az', 'za', 'newest', 'oldest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'editor' => ['nullable', Rule::in(['create'])],
            'lookup' => ['nullable', Rule::in($lookups)],
            'term' => ['nullable', 'string', 'max:120'],
            'lookup_page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'association_id' => ['required_if:lookup,projects,members,training_choices', 'nullable', 'uuid'],
            'training_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'from_monitoring' => ['nullable', 'boolean'],
            'resource_section' => ['nullable', Rule::in(['products', 'materials'])],
            'monitoring_record' => ['nullable', 'uuid'],
        ];
    }

    public function filters(): array
    {
        return ['q' => $this->validated('q') ?? '', 'association' => $this->validated('association') ?? '',
            'year' => $this->validated('year') ?? '', 'status' => $this->validated('status') ?? '',
            'sort' => $this->validated('sort') ?? 'year'];
    }
}
