<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MonitoringIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'validated' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(array_keys($this->sortOptions()))],
        ];

        if (! $this->routeIs('*.monitoring.visits')) {
            $rules['year'] = ['nullable', 'integer', 'between:2000,2100'];
            $rules['quarter'] = ['nullable', 'integer', 'between:1,4'];
        }

        return $rules;
    }

    public function sortOptions(): array
    {
        return $this->routeIs('*.monitoring.visits')
            ? ['newest' => 'Visit date: newest first', 'oldest' => 'Visit date: oldest first']
            : ['newest' => 'Latest matching record: newest first', 'oldest' => 'Earliest matching record: oldest first', 'quarter' => 'First matching quarter: Q1 to Q4'];
    }

    public function filters(): array
    {
        $data = $this->validated();

        return [
            'search' => trim($data['search'] ?? ''),
            'year' => $data['year'] ?? '',
            'quarter' => $data['quarter'] ?? '',
            'validated' => isset($data['validated']) ? ((bool) $data['validated'] ? '1' : '0') : '',
            'sort' => $data['sort'] ?? 'newest',
        ];
    }
}
