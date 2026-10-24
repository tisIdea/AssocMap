<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssociationProgramRequest extends FormRequest
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
        $rules = ['page' => ['nullable', 'integer', 'min:1', 'max:1000000']];
        if ($this->routeIs('association.trainings.index', 'association.program-activities.index')) {
            $rules['search'] = ['nullable', 'string', 'max:120'];
            $rules['year'] = ['nullable', 'integer', 'between:2000,2100'];
        }

        return $rules;
    }

    public function filters(): array
    {
        return ['search' => $this->validated('search') ?? '', 'year' => $this->validated('year') ?? ''];
    }
}
