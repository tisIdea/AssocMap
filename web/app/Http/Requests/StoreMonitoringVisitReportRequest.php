<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMonitoringVisitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array(
            $this->attributes->get('current_user')?->role?->code,
            ['system_admin', 'field_officer'],
            true
        );
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('*.monitoring.visit.store', '*.monitoring.visit.update')) {
            $this->errorBag = 'monitoringVisit';
        }
        foreach (['issues_encountered', 'actions_taken', 'overall_remarks', 'conducted_by'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => trim($value) === '' ? null : trim($value)]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'conducted_by_user_id' => ['nullable', 'uuid'],
            'conducted_by' => ['nullable', 'string', 'max:255'],
            'monitoring_date' => ['required', 'date'],
            'site_validated' => ['required', 'boolean'],
            'issues_encountered' => ['nullable', 'string'],
            'actions_taken' => ['nullable', 'string'],
            'overall_remarks' => ['nullable', 'string'],
            'id' => ['prohibited'],
            'monitoring_record_id' => ['prohibited'],
            'monitored_by' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'is_legacy_import' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
