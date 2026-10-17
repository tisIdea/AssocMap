<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AreaIndexRequest extends FormRequest
{
    protected $redirectRoute = 'admin.areas.index';

    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor?->canAccessApplication() && $actor->role?->code === 'system_admin';
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('q'))) $this->merge(['q' => trim($this->query('q'))]);
    }

    public function rules(): array
    {
        return [
            'tab' => ['nullable', 'string', Rule::in(['municipalities', 'barangays'])],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'source' => ['nullable', 'string', Rule::in(['psgc', 'manual'])],
            'type' => ['nullable', 'string', Rule::in(['city', 'municipality'])],
            'municipality_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'string', Rule::in(['az', 'za', 'newest', 'oldest', 'municipality', 'status'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'barangays_page' => ['nullable', 'integer', 'min:1'],
            'associations_page' => ['nullable', 'integer', 'min:1'],
            'areas_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        return [
            'tab' => $this->validated('tab') ?? 'municipalities',
            'q' => $this->validated('q') ?? '',
            'status' => $this->validated('status') ?? '',
            'source' => $this->validated('source') ?? '',
            'type' => ($this->validated('tab') === 'barangays') ? '' : ($this->validated('type') ?? ''),
            'municipality_id' => $this->validated('municipality_id') ?? '',
            'sort' => $this->validated('sort') ?? 'az',
        ];
    }
}
