<?php

namespace App\Http\Requests;

class UpdateMonitoringSectionRequest extends StoreMonitoringRequest
{
    public function rules(): array
    {
        $section = $this->route('section');
        abort_unless(in_array($section, ['production', 'income', 'materials'], true), 404);
        $rules = parent::rules();

        return array_filter($rules, function ($key) use ($section) {
            if (in_array($key, ['project_id', 'year', 'quarter_id'], true)) {
                return true;
            }
            if ($section === 'production' && in_array($key, ['project_status', 'production_revision'], true)) {
                return true;
            }
            if ($section === 'income') {
                return in_array($key, ['income_revision', 'production', 'production.*', 'production.*.id', 'production.*.project_product_id'], true)
                    || str_contains($key, '.deleted_income_ids')
                    || str_starts_with($key, 'production.*.income')
                    || preg_match('/^production\.\d+\.income\./', $key);
            }
            if ($section === 'materials' && in_array($key, ['deleted_material_ids', 'deleted_material_ids.*'], true)) {
                return true;
            }
            return str_starts_with($key, $section === 'materials' ? 'materials' : 'production')
                && ! str_contains($key, '.income');
        }, ARRAY_FILTER_USE_KEY);
    }
}
