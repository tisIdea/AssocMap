<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssociationProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('current_user')?->role?->code === 'association_account';
    }

    protected function getRedirectUrl()
    {
        return route($this->route()->getName(), $this->route()->parameters());
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('search'))) $this->merge(['search' => trim($this->query('search'))]);
    }

    public function rules(): array
    {
        if ($this->routeIs('association.projects.index')) {
            return [
                'search' => ['nullable', 'string', 'max:120'],
                'year' => ['nullable', 'integer', 'between:2000,2100'],
                'status' => ['nullable', 'string', Rule::in(['planned', 'ongoing', 'completed', 'suspended', 'cancelled', 'terminated', 'archived'])],
                'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            ];
        }

        return array_fill_keys(['products_page', 'materials_page', 'monitoring_page'],
            ['nullable', 'integer', 'min:1', 'max:1000000']);
    }

    public function filters(): array
    {
        return ['search' => $this->validated('search') ?? '', 'year' => $this->validated('year') ?? '',
            'status' => $this->validated('status') ?? ''];
    }
}
