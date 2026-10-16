<?php

namespace App\Http\Requests;

use App\Models\ProjectProduct;
use App\Models\Quarter;
use App\Services\ProductionMonitoringService;
use Illuminate\Support\Str;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->attributes->get('current_user')?->role?->code,
            ['system_admin', 'field_officer'], true);
    }

    protected function prepareForValidation(): void
    {
        $production = $this->input('production', []);

        if (is_array($production)) {
            foreach ($production as $productionIndex => $productionItem) {
                if (! is_array($productionItem)) {
                    continue;
                }

                if (isset($productionItem['unit']) && is_string($productionItem['unit'])) {
                    $production[$productionIndex]['unit'] =
                        trim((string) $productionItem['unit']);
                }

                if (array_key_exists('remarks', $productionItem) && is_string($productionItem['remarks'])) {
                    $production[$productionIndex]['remarks'] =
                        $productionItem['remarks'] !== null
                        && $productionItem['remarks'] !== ''
                            ? trim((string) $productionItem['remarks'])
                            : null;
                }

                $income =
                    $productionItem['income'] ?? [];

                if (is_array($income)) {
                    foreach ($income as $incomeIndex => $incomeItem) {
                        if (! is_array($incomeItem)) {
                            continue;
                        }

                        if (array_key_exists('remarks', $incomeItem)) {
                            $production[$productionIndex]
                                ['income'][$incomeIndex]['remarks'] =
                                    $incomeItem['remarks'] !== null
                                    && $incomeItem['remarks'] !== ''
                                        ? trim(
                                            (string) $incomeItem['remarks']
                                        )
                                        : null;
                        }
                    }
                }
            }
        }

        $materials = $this->input('materials', []);

        if (is_array($materials)) {
            foreach ($materials as $materialIndex => $material) {
                if (! is_array($material)) {
                    continue;
                }

                if (isset($material['condition'])) {
                    $materials[$materialIndex]['condition'] =
                        trim((string) $material['condition']);
                }

                if (array_key_exists('remarks', $material)) {
                    $materials[$materialIndex]['remarks'] =
                        $material['remarks'] !== null
                        && $material['remarks'] !== ''
                            ? trim((string) $material['remarks'])
                            : null;
                }
            }
        }

        $this->merge([
            'issues_encountered' =>
                $this->filled('issues_encountered')
                    ? trim(
                        (string) $this->input(
                            'issues_encountered'
                        )
                    )
                    : null,

            'actions_taken' =>
                $this->filled('actions_taken')
                    ? trim(
                        (string) $this->input(
                            'actions_taken'
                        )
                    )
                    : null,

            'overall_remarks' =>
                $this->filled('overall_remarks')
                    ? trim(
                        (string) $this->input(
                            'overall_remarks'
                        )
                    )
                    : null,

            'materials' => $materials,
        ] + ($this->exists('production') ? ['production' => $production] : []));
    }

    public function rules(): array
    {
        $record = $this->route('monitoringRecord');
        $projectId = $this->input('project_id');
        $projectId = is_string($projectId) && Str::isUuid($projectId) ? $projectId : null;

        $rules = [
            /*
            |--------------------------------------------------------------------------
            | MONITORING RECORD
            |--------------------------------------------------------------------------
            */

            'project_id' => [
                'required',
                'uuid',
                'exists:projects,id',
            ],

            'year' => [
                'required',
                'integer',
                'between:2000,2100',
            ],

            'quarter_id' => [
                'required',
                'integer',
                'exists:quarters,id',
            ],

            'monitoring_date' => [
                'required',
                'date',
            ],

            'site_validated' => [
                'required',
                'boolean',
            ],

            'project_status' => [
                'required',
                Rule::in([
                    'planned',
                    'ongoing',
                    'completed',
                    'suspended',
                    'cancelled',
                ]),
            ],

            'issues_encountered' => [
                'nullable',
                'string',
            ],

            'actions_taken' => [
                'nullable',
                'string',
            ],

            'overall_remarks' => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | PRODUCTION MONITORING
            |--------------------------------------------------------------------------
            */

            'conducted_by_user_id' => ['nullable', 'uuid'],
            'conducted_by' => ['nullable', 'string', 'max:255'],
            'production_revision' => [$record !== null && ($this->route('section') === 'production' || ($this->route('section') === null && $this->exists('production'))) ? 'required' : 'nullable', 'string', 'size:64'],
            'production.*.monthly_details' => ['sometimes', 'array', 'size:3'],
            'production.*.monthly_details.*' => ['array:id,month,activity_count,total_quantity,remarks'],
            'production.*.monthly_details.*.id' => ['nullable', 'uuid'],
            'production.*.monthly_details.*.month' => ['required', 'integer', 'between:1,12'],
            'production.*.monthly_details.*.activity_count' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'production.*.monthly_details.*.total_quantity' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/D'],
            'production.*.monthly_details.*.remarks' => ['nullable', 'string'],
            'production.*.deleted_monthly_ids' => ['prohibited'],
            'production.*.deleted_monthly_ids.*' => ['required', 'uuid', 'distinct'],
            'production' => [
                'nullable',
                'array',
            ],

            'production.*.id' => [
                'nullable',
                'uuid',
                Rule::exists('monitoring_production', 'id')->where('monitoring_record_id', $record?->getKey()),
                Rule::prohibitedIf($record === null),
            ],

            'production.*.project_product_id' => [
                'nullable',
                'uuid',
                Rule::exists('project_products', 'id')->where('project_id', $projectId),
            ],

            'production.*.target_output' => [
                'sometimes',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:999999999999.99',
            ],

            'production.*.actual_output' => [
                'exclude',
            ],

            'production.*.unit' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'production.*.remarks' => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | INCOME MONITORING
            |--------------------------------------------------------------------------
            */

            'production.*.income' => [
                'nullable',
                'array',
            ],

            'production.*.income.*.id' => [
                'nullable',
                'uuid',
                'exists:monitoring_income,id',
            ],

            'production.*.income.*.period_type' => [
                'required_with:production.*.income',
                Rule::in([
                    'monthly',
                    'quarterly',
                ]),
            ],

            'production.*.income.*.month' => [
                'nullable',
                'integer',
                'between:1,12',
            ],

            'production.*.income.*.gross_income' => [
                'required_with:production.*.income',
                'numeric',
                'min:0',
                'max:999999999999.99',
            ],

            'production.*.income.*.remarks' => [
                'nullable',
                'string',
            ],

            /*
            |--------------------------------------------------------------------------
            | MATERIAL MONITORING
            |--------------------------------------------------------------------------
            */

            'materials' => [
                'nullable',
                'array',
            ],

            'materials.*.id' => [
                'nullable',
                'uuid',
                Rule::exists('monitoring_materials', 'id')->where('monitoring_record_id', $record?->getKey()),
                Rule::prohibitedIf($record === null),
            ],

            'materials.*.project_material_id' => [
                'nullable',
                'uuid',
                Rule::exists('project_materials', 'id')->where('project_id', $projectId),
            ],

            'materials.*.condition' => [
                'required_with:materials',
                'string',
                'max:50',
            ],

            'materials.*.scheduled_maintenance' => [
                'nullable',
                'date',
            ],

            'materials.*.actual_maintenance' => [
                'nullable',
                'date',
            ],

            'materials.*.remarks' => [
                'nullable',
                'string',
            ],
        ];

        $rules['production.*'] = ['array'];
        $rules['materials.*'] = ['array'];
        $rules['production.*.income.*'] = ['array'];

        foreach (['production' => 'new_product', 'materials' => 'new_material'] as $collection => $payload) {
            $rows = $this->input($collection, []);
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $prefix = "{$collection}.{$index}.{$payload}";
                if ($collection === 'production' && (empty($row['id']) || array_key_exists('monthly_details', $row))) {
                    $rules["production.{$index}.target_output"] = ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/D'];
                    $rules["production.{$index}.unit"] = ['required', 'string', 'max:50'];
                    $rules["production.{$index}.monthly_details"] = ['required', 'array', 'size:3'];
                }
                $new = array_key_exists($payload, $row) && $row[$payload] !== null;
                $rules[$prefix] = $record !== null ? ['prohibited'] : ['nullable', $payload === 'new_product'
                    ? 'array:product_name,product_type,default_unit,description,is_active'
                    : 'array:item_name,quantity_planned,quantity_delivered,unit,unit_cost,delivery_status,delivery_date,remarks'];

                if ($new && $record === null) {
                    $fields = $payload === 'new_product' ? [
                        'product_name' => ['required', 'string', 'max:255'],
                        'product_type' => ['required', Rule::in(['raw', 'processed', 'other'])],
                        'default_unit' => ['nullable', 'string', 'max:50'],
                        'description' => ['nullable', 'string'],
                        'is_active' => ['nullable', 'boolean'],
                    ] : [
                        'item_name' => ['required', 'string', 'max:255'],
                        'quantity_planned' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
                        'quantity_delivered' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
                        'unit' => ['required', 'string', 'max:50'],
                        'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
                        'delivery_status' => ['required', Rule::in(['planned', 'not_delivered', 'partially_delivered', 'delivered'])],
                        'delivery_date' => ['nullable', 'date'],
                        'remarks' => ['nullable', 'string'],
                    ];
                    foreach ($fields as $field => $fieldRules) {
                        $rules["{$prefix}.{$field}"] = $fieldRules;
                    }
                }

                if ($collection === 'production' && is_array($row['income'] ?? null)) {
                    $parentId = $row['id'] ?? null;
                    $parentId = is_string($parentId) && Str::isUuid($parentId) ? $parentId : null;
                    foreach ($row['income'] as $incomeIndex => $income) {
                        $rules["production.{$index}.income.{$incomeIndex}.id"] = [
                            'nullable', 'uuid', Rule::prohibitedIf($record === null || $parentId === null),
                            Rule::exists('monitoring_income', 'id')->where('monitoring_production_id', $parentId),
                        ];
                    }
                }
            }
        }

        if ($record !== null) {
            foreach (['project_id', 'year', 'quarter_id'] as $field) {
                $rules[$field][] = Rule::in([$record->getAttribute($field)]);
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'project_id.in' => 'Project cannot be changed after the Monitoring record has been created.',
            'year.in' => 'Year cannot be changed after the Monitoring record has been created.',
            'quarter_id.in' => 'Quarter cannot be changed after the Monitoring record has been created.',
            'production.*.unit.max' => 'Production unit must not exceed 50 characters.',
            'production.*.monthly_details.required' => 'Record all three months of the selected quarter. Enter zero explicitly for months without production.',
            'production.*.monthly_details.size' => 'Production requires exactly three monthly entries for the selected quarter.',
            'production.*.monthly_details.*.activity_count.required' => 'Enter the monthly activity count, including zero when confirmed.',
            'production.*.monthly_details.*.activity_count.min' => 'Production activity counts cannot be negative.',
            'production.*.monthly_details.*.total_quantity.required' => 'Enter the monthly production quantity, including zero when confirmed.',
            'production.*.monthly_details.*.total_quantity.regex' => 'Use a nonnegative quantity with at most 12 whole digits and 2 decimal places.',
            'production.*.target_output.regex' => 'Use a nonnegative Target Output with at most 12 whole digits and 2 decimal places.',
            'production.*.deleted_monthly_ids.prohibited' => 'Saved monthly Production must be retained.',
            'production_revision.required' => 'Reload Production before saving this outdated form.',
            'production_revision.size' => 'Reload Production before saving this outdated form.',
            'materials.*.condition.max' => 'Material condition must not exceed 50 characters.',
            'production.*.project_product_id.exists' => 'Select a product belonging to the selected project.',
            'materials.*.project_material_id.exists' => 'Select a material belonging to the selected project.',
            'production.*.new_product.prohibited' => 'New products can only be registered when adding Monitoring.',
            'materials.*.new_material.prohibited' => 'New materials can only be registered when adding Monitoring.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateResourceSelections($validator);
                $this->validateMonthlyProduction($validator);

                $this->validateIncomePeriods(
                    $validator
                );

                $this->validateDuplicateProducts(
                    $validator
                );

                $this->validateDuplicateMaterials(
                    $validator
                );
            },
        ];
    }

    private function validateMonthlyProduction(Validator $validator): void
    {
        if ($this->route('section') === 'income' || $this->route('section') === 'materials'
            || $validator->errors()->has('quarter_id') || $validator->errors()->has('year')
            || ! is_array($this->input('production'))) return;
        $quarter = Quarter::find($this->input('quarter_id'));
        if (! $quarter) return;
        foreach ($this->input('production') as $index => $row) {
            if (! is_array($row) || (! empty($row['id']) && ! array_key_exists('monthly_details', $row))) continue;
            try {
                app(ProductionMonitoringService::class)->calculateActualOutput((int) $this->input('year'),
                    $quarter->quarter_number, $row['monthly_details'] ?? [], "production.{$index}");
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) $validator->errors()->add($field, $message);
                }
            }
        }
    }

    private function validateResourceSelections(Validator $validator): void
    {
        $productNames = [];
        foreach (['production' => ['project_product_id', 'new_product'], 'materials' => ['project_material_id', 'new_material']] as $collection => [$reference, $payload]) {
            $rows = $this->input($collection, []);
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $existing = isset($row[$reference]) && $row[$reference] !== '';
                $new = array_key_exists($payload, $row) && $row[$payload] !== null;
                if ($this->route('monitoringRecord') !== null && $new) {
                    $validator->errors()->add("{$collection}.{$index}.{$payload}", 'Inline registration is available only when adding Monitoring.');
                }
                if ($existing === $new) {
                    $validator->errors()->add("{$collection}.{$index}.{$reference}", 'Select an existing resource or register a new resource, using exactly one option.');
                }

                if ($payload !== 'new_product' || ! is_array($row[$payload] ?? null)) {
                    continue;
                }
                $name = $row[$payload]['product_name'] ?? null;
                if (! is_string($name)) {
                    continue;
                }
                $name = trim($name);
                $key = mb_strtolower($name);
                $field = "production.{$index}.new_product.product_name";
                if ($name === '') {
                    $validator->errors()->add($field, 'Enter the new project product name.');
                    continue;
                }
                if (isset($productNames[$key])) {
                    $validator->errors()->add($field, 'The same new product cannot be registered twice in this submission.');
                }
                $productNames[$key] = true;
                if (! $validator->errors()->has('project_id') && ProjectProduct::query()
                    ->where('project_id', $this->input('project_id'))
                    ->whereRaw('LOWER(product_name) = LOWER(?)', [$name])->exists()) {
                    $validator->errors()->add($field, 'This product already exists under the project. Select the existing product instead.');
                }
            }
        }
    }

    private function validateIncomePeriods(
        Validator $validator
    ): void {
        $production =
            $this->input('production', []);

        if (! is_array($production)) {
            return;
        }

        $quarterNumber = $validator->errors()->has('quarter_id') ? null : Quarter::query()
            ->where('id', $this->input('quarter_id'))->value('quarter_number');

        foreach (
            $production as $productionIndex => $productionItem
        ) {
            if (! is_array($productionItem)) {
                continue;
            }

            $income =
                $productionItem['income'] ?? [];

            if (! is_array($income)) {
                continue;
            }

            $monthlyMonths = [];
            $periodTypes = [];
            $quarterlyCount = 0;

            foreach (
                $income as $incomeIndex => $incomeItem
            ) {
                if (! is_array($incomeItem)) {
                    continue;
                }

                $periodType =
                    $incomeItem['period_type'] ?? null;

                $month =
                    $incomeItem['month'] ?? null;

                if (in_array($periodType, ['monthly', 'quarterly'], true)) {
                    $periodTypes[$periodType] = true;
                    if (count($periodTypes) > 1) {
                        $validator->errors()->add("production.{$productionIndex}.income.{$incomeIndex}.period_type", 'Use monthly or quarterly income for this production entry, not both.');
                    }
                }

                if ($periodType === 'monthly') {
                    if (
                        $month === null
                        || $month === ''
                    ) {
                        $validator->errors()->add(
                            "production.{$productionIndex}.income.{$incomeIndex}.month",
                            'Month is required for monthly income records.'
                        );

                        continue;
                    }

                    $monthNumber =
                        (int) $month;

                    if ($quarterNumber !== null) {
                        $firstMonth = (((int) $quarterNumber - 1) * 3) + 1;
                        if ($monthNumber < $firstMonth || $monthNumber > $firstMonth + 2) {
                            $validator->errors()->add("production.{$productionIndex}.income.{$incomeIndex}.month", 'Select a month within the selected monitoring quarter and year.');
                        }
                    }

                    if (
                        in_array(
                            $monthNumber,
                            $monthlyMonths,
                            true
                        )
                    ) {
                        $validator->errors()->add(
                            "production.{$productionIndex}.income.{$incomeIndex}.month",
                            'The same month cannot be entered more than once for this production record.'
                        );
                    }

                    $monthlyMonths[] =
                        $monthNumber;
                }

                if ($periodType === 'quarterly') {
                    $quarterlyCount++;

                    if (
                        $month !== null
                        && $month !== ''
                    ) {
                        $validator->errors()->add(
                            "production.{$productionIndex}.income.{$incomeIndex}.month",
                            'Month must be empty for quarterly income records.'
                        );
                    }

                    if ($quarterlyCount > 1) {
                        $validator->errors()->add(
                            "production.{$productionIndex}.income.{$incomeIndex}.period_type",
                            'Only one quarterly income record may be entered for the same production record.'
                        );
                    }
                }
            }
        }
    }

    private function validateDuplicateProducts(
        Validator $validator
    ): void {
        $production =
            $this->input('production', []);

        if (! is_array($production)) {
            return;
        }

        $productIds = [];

        foreach (
            $production as $productionIndex => $productionItem
        ) {
            if (! is_array($productionItem)) {
                continue;
            }

            $productId =
                $productionItem[
                    'project_product_id'
                ] ?? null;

            if (! $productId) {
                continue;
            }

            if (
                in_array(
                    $productId,
                    $productIds,
                    true
                )
            ) {
                $validator->errors()->add(
                    "production.{$productionIndex}.project_product_id",
                    'The same project product cannot be monitored more than once in the same monitoring record.'
                );
            }

            $productIds[] =
                $productId;
        }
    }

    private function validateDuplicateMaterials(
        Validator $validator
    ): void {
        $materials =
            $this->input('materials', []);

        if (! is_array($materials)) {
            return;
        }

        $materialIds = [];

        foreach (
            $materials as $materialIndex => $material
        ) {
            if (! is_array($material)) {
                continue;
            }

            $materialId =
                $material[
                    'project_material_id'
                ] ?? null;

            if (! $materialId) {
                continue;
            }

            if (
                in_array(
                    $materialId,
                    $materialIds,
                    true
                )
            ) {
                $validator->errors()->add(
                    "materials.{$materialIndex}.project_material_id",
                    'The same project material cannot be monitored more than once in the same monitoring record.'
                );
            }

            $materialIds[] =
                $materialId;
        }
    }
}
