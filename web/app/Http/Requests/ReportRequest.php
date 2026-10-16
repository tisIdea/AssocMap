<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use App\Services\Reporting\ReportCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->attributes->get('current_user');

        return $actor instanceof UserProfile && $actor->canAccessApplication()
            && in_array($actor->role?->code, ['system_admin', 'field_officer', 'association_account'], true);
    }

    public function rules(): array
    {
        return [
            'report_type' => ['sometimes', 'required', 'string', Rule::in(array_keys(ReportCatalog::TYPES))],
            'municipality_id' => ['nullable', 'uuid'],
            'association_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'program_year' => ['nullable', 'integer', 'between:2000,2100'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'component_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $unknown = array_diff(array_keys($this->all()), array_merge(array_keys($this->rules()), ['_token']));
            if ($unknown) $validator->errors()->add('filters', 'Unsupported report filter.');
            $type = $this->input('report_type', 'comprehensive');
            if (! is_string($type) || ! isset(ReportCatalog::TYPES[$type])) return;
            foreach (array_diff(array_keys($this->rules()), ['report_type'], ReportCatalog::filters($type)) as $key) {
                if ($this->filled($key)) $validator->errors()->add($key, 'This filter does not apply to the selected report.');
            }
            $month = $this->input('month');
            $quarter = $this->input('quarter');
            if (is_scalar($month) && is_scalar($quarter) && $month && $quarter
                && (int) ceil((int) $month / 3) !== (int) $quarter) {
                $validator->errors()->add('month', 'The month must belong to the selected quarter.');
            }
        }];
    }

    public function filters(): array
    {
        $filters = array_filter($this->validated(), fn ($value) => $value !== null && $value !== '');
        $filters['report_type'] ??= 'comprehensive';
        if (in_array('year', ReportCatalog::filters($filters['report_type']), true)) {
            $filters['year'] ??= (int) now('Asia/Manila')->format('Y');
        }
        foreach (['year', 'program_year', 'quarter', 'month', 'component_id'] as $key) {
            if (isset($filters[$key])) $filters[$key] = (int) $filters[$key];
        }
        foreach (['municipality_id', 'association_id', 'project_id', 'product_id'] as $key) {
            if (isset($filters[$key])) $filters[$key] = strtolower($filters[$key]);
        }

        return $filters;
    }
}
