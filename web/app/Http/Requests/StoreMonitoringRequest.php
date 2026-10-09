<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $production = $this->input('production', []);

        if (is_array($production)) {
            foreach ($production as $productionIndex => $productionItem) {
                if (! is_array($productionItem)) {
                    continue;
                }

                if (isset($productionItem['unit'])) {
                    $production[$productionIndex]['unit'] =
                        trim((string) $productionItem['unit']);
                }

                if (array_key_exists('remarks', $productionItem)) {
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

            'production' => $production,

            'materials' => $materials,
        ]);
    }

    public function rules(): array
    {
        return [
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

            'production' => [
                'nullable',
                'array',
            ],

            'production.*.id' => [
                'nullable',
                'uuid',
                'exists:monitoring_production,id',
            ],

            'production.*.project_product_id' => [
                'required_with:production',
                'uuid',
                'exists:project_products,id',
            ],

            'production.*.target_output' => [
                'required_with:production',
                'numeric',
                'min:0',
            ],

            'production.*.actual_output' => [
                'required_with:production',
                'numeric',
                'min:0',
            ],

            'production.*.unit' => [
                'required_with:production',
                'string',
                'max:100',
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
                'exists:monitoring_materials,id',
            ],

            'materials.*.project_material_id' => [
                'required_with:materials',
                'uuid',
                'exists:project_materials,id',
            ],

            'materials.*.condition' => [
                'required_with:materials',
                'string',
                'max:100',
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
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
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

    private function validateIncomePeriods(
        Validator $validator
    ): void {
        $production =
            $this->input('production', []);

        if (! is_array($production)) {
            return;
        }

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