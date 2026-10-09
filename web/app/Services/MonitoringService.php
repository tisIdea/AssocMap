<?php

namespace App\Services;

use App\Models\Association;
use App\Models\MonitoringIncome;
use App\Models\MonitoringMaterial;
use App\Models\MonitoringProduction;
use App\Models\MonitoringRecord;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectProduct;
use App\Models\Quarter;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitoringService
{
    public function create(
        array $data,
        UserProfile $currentUser
    ): MonitoringRecord {
        $project = $this->resolveProjectForUser(
            $data['project_id'],
            $currentUser
        );

        $this->resolveQuarter(
            (int) $data['quarter_id']
        );

        $this->ensureMonitoringPeriodIsUnique(
            $project,
            (int) $data['year'],
            (int) $data['quarter_id']
        );

        return DB::transaction(function () use (
            $data,
            $currentUser,
            $project
        ) {
            $record = MonitoringRecord::create([
                'project_id' =>
                    $project->id,

                'year' =>
                    $data['year'],

                'quarter_id' =>
                    $data['quarter_id'],

                'monitoring_date' =>
                    $data['monitoring_date'],

                'monitored_by' =>
                    $currentUser->id,

                'site_validated' =>
                    (bool) $data['site_validated'],

                'project_status' =>
                    $data['project_status'],

                'issues_encountered' =>
                    $data['issues_encountered']
                        ?? null,

                'actions_taken' =>
                    $data['actions_taken']
                        ?? null,

                'overall_remarks' =>
                    $data['overall_remarks']
                        ?? null,

                'created_by' =>
                    $currentUser->id,

                'updated_by' =>
                    $currentUser->id,
            ]);

            $this->createProduction(
                $record,
                $project,
                $data['production'] ?? []
            );

            $this->createMaterials(
                $record,
                $project,
                $data['materials'] ?? []
            );

            return $this->loadMonitoringRecord(
                $record
            );
        });
    }

    public function update(
        MonitoringRecord $record,
        array $data,
        UserProfile $currentUser
    ): MonitoringRecord {
        $this->ensureUserCanManageMonitoring(
            $record,
            $currentUser
        );

        $project = $this->resolveProjectForUser(
            $data['project_id'],
            $currentUser
        );

        $this->resolveQuarter(
            (int) $data['quarter_id']
        );

        $this->ensureMonitoringPeriodIsUnique(
            $project,
            (int) $data['year'],
            (int) $data['quarter_id'],
            $record->id
        );

        return DB::transaction(function () use (
            $record,
            $data,
            $currentUser,
            $project
        ) {
            $record->update([
                'project_id' =>
                    $project->id,

                'year' =>
                    $data['year'],

                'quarter_id' =>
                    $data['quarter_id'],

                'monitoring_date' =>
                    $data['monitoring_date'],

                'monitored_by' =>
                    $currentUser->id,

                'site_validated' =>
                    (bool) $data['site_validated'],

                'project_status' =>
                    $data['project_status'],

                'issues_encountered' =>
                    $data['issues_encountered']
                        ?? null,

                'actions_taken' =>
                    $data['actions_taken']
                        ?? null,

                'overall_remarks' =>
                    $data['overall_remarks']
                        ?? null,

                'updated_by' =>
                    $currentUser->id,
            ]);

            if (
                array_key_exists(
                    'production',
                    $data
                )
            ) {
                $this->syncProduction(
                    $record,
                    $project,
                    $data['production'] ?? []
                );
            }

            if (
                array_key_exists(
                    'materials',
                    $data
                )
            ) {
                $this->syncMaterials(
                    $record,
                    $project,
                    $data['materials'] ?? []
                );
            }

            return $this->loadMonitoringRecord(
                $record
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | PROJECT / ROLE AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    private function resolveProjectForUser(
        string $projectId,
        UserProfile $currentUser
    ): Project {
        $project = Project::query()
            ->where('id', $projectId)
            ->whereNull('archived_at')
            ->first();

        if (! $project) {
            throw ValidationException::withMessages([
                'project_id' =>
                    'The selected project is unavailable or archived.',
            ]);
        }

        $roleCode =
            $currentUser->role?->code;

        if ($roleCode === 'system_admin') {
            return $project;
        }

        if ($roleCode === 'field_officer') {
            $isAssigned =
                Association::query()
                    ->where(
                        'id',
                        $project->association_id
                    )
                    ->whereNull(
                        'archived_at'
                    )
                    ->where(
                        'status',
                        'active'
                    )
                    ->where(
                        'primary_field_officer_id',
                        $currentUser->id
                    )
                    ->exists();

            if ($isAssigned) {
                return $project;
            }

            throw ValidationException::withMessages([
                'project_id' =>
                    'You can only monitor projects belonging to associations assigned to you.',
            ]);
        }

        throw ValidationException::withMessages([
            'authorization' =>
                'You are not authorized to manage monitoring records.',
        ]);
    }

    private function ensureUserCanManageMonitoring(
        MonitoringRecord $record,
        UserProfile $currentUser
    ): void {
        $project =
            Project::query()
                ->where(
                    'id',
                    $record->project_id
                )
                ->first();

        if (! $project) {
            throw ValidationException::withMessages([
                'monitoring' =>
                    'The project associated with this monitoring record could not be found.',
            ]);
        }

        $roleCode =
            $currentUser->role?->code;

        if ($roleCode === 'system_admin') {
            return;
        }

        if ($roleCode === 'field_officer') {
            $isAssigned =
                Association::query()
                    ->where(
                        'id',
                        $project->association_id
                    )
                    ->where(
                        'primary_field_officer_id',
                        $currentUser->id
                    )
                    ->exists();

            if ($isAssigned) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'authorization' =>
                'You are not authorized to manage this monitoring record.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | QUARTER + PERIOD VALIDATION
    |--------------------------------------------------------------------------
    */

    private function resolveQuarter(
        int $quarterId
    ): Quarter {
        $quarter =
            Quarter::query()
                ->where(
                    'id',
                    $quarterId
                )
                ->first();

        if (! $quarter) {
            throw ValidationException::withMessages([
                'quarter_id' =>
                    'The selected quarter is invalid.',
            ]);
        }

        return $quarter;
    }

    private function ensureMonitoringPeriodIsUnique(
        Project $project,
        int $year,
        int $quarterId,
        ?string $ignoreRecordId = null
    ): void {
        $query =
            MonitoringRecord::query()
                ->where(
                    'project_id',
                    $project->id
                )
                ->where(
                    'year',
                    $year
                )
                ->where(
                    'quarter_id',
                    $quarterId
                );

        if ($ignoreRecordId) {
            $query->where(
                'id',
                '!=',
                $ignoreRecordId
            );
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'quarter_id' =>
                    'A monitoring record already exists for this project, year, and quarter.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCTION
    |--------------------------------------------------------------------------
    */

    private function createProduction(
        MonitoringRecord $record,
        Project $project,
        array $productionRows
    ): void {
        foreach (
            $productionRows as $production
        ) {
            $product =
                $this->resolveProjectProduct(
                    $project,
                    $production[
                        'project_product_id'
                    ]
                );

            $productionRecord =
                $record->production()->create([
                    'project_product_id' =>
                        $product->id,

                    'target_output' =>
                        $production[
                            'target_output'
                        ],

                    'actual_output' =>
                        $production[
                            'actual_output'
                        ],

                    'unit' =>
                        trim(
                            $production['unit']
                        ),

                    'remarks' =>
                        $production[
                            'remarks'
                        ] ?? null,
                ]);

            $this->createIncome(
                $productionRecord,
                $production[
                    'income'
                ] ?? []
            );
        }
    }

    private function syncProduction(
        MonitoringRecord $record,
        Project $project,
        array $productionRows
    ): void {
        $submittedIds = [];

        foreach (
            $productionRows as $production
        ) {
            $product =
                $this->resolveProjectProduct(
                    $project,
                    $production[
                        'project_product_id'
                    ]
                );

            $productionId =
                $production['id']
                    ?? null;

            if ($productionId) {
                if (
                    in_array(
                        $productionId,
                        $submittedIds,
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'production' =>
                            'The same production record cannot be submitted more than once.',
                    ]);
                }

                $existing =
                    MonitoringProduction::query()
                        ->where(
                            'id',
                            $productionId
                        )
                        ->where(
                            'monitoring_record_id',
                            $record->id
                        )
                        ->first();

                if (! $existing) {
                    throw ValidationException::withMessages([
                        'production' =>
                            'One of the submitted production records does not belong to this monitoring record.',
                    ]);
                }

                $existing->update([
                    'project_product_id' =>
                        $product->id,

                    'target_output' =>
                        $production[
                            'target_output'
                        ],

                    'actual_output' =>
                        $production[
                            'actual_output'
                        ],

                    'unit' =>
                        trim(
                            $production['unit']
                        ),

                    'remarks' =>
                        $production[
                            'remarks'
                        ] ?? null,
                ]);

                if (
                    array_key_exists(
                        'income',
                        $production
                    )
                ) {
                    $this->syncIncome(
                        $existing,
                        $production[
                            'income'
                        ] ?? []
                    );
                }

                $submittedIds[] =
                    $existing->id;

                continue;
            }

            $created =
                $record->production()->create([
                    'project_product_id' =>
                        $product->id,

                    'target_output' =>
                        $production[
                            'target_output'
                        ],

                    'actual_output' =>
                        $production[
                            'actual_output'
                        ],

                    'unit' =>
                        trim(
                            $production['unit']
                        ),

                    'remarks' =>
                        $production[
                            'remarks'
                        ] ?? null,
                ]);

            $this->createIncome(
                $created,
                $production[
                    'income'
                ] ?? []
            );

            $submittedIds[] =
                $created->id;
        }

        $removed =
            MonitoringProduction::query()
                ->where(
                    'monitoring_record_id',
                    $record->id
                )
                ->when(
                    ! empty($submittedIds),
                    fn ($query) =>
                        $query->whereNotIn(
                            'id',
                            $submittedIds
                        )
                )
                ->get();

        foreach ($removed as $production) {
            /*
             * monitoring_income uses ON DELETE CASCADE,
             * so its income rows are removed together.
             */
            $production->delete();
        }
    }

    private function resolveProjectProduct(
        Project $project,
        string $productId
    ): ProjectProduct {
        $product =
            ProjectProduct::query()
                ->where(
                    'id',
                    $productId
                )
                ->where(
                    'project_id',
                    $project->id
                )
                ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'production' =>
                    'One of the selected products does not belong to the selected project.',
            ]);
        }

        return $product;
    }

    /*
    |--------------------------------------------------------------------------
    | INCOME
    |--------------------------------------------------------------------------
    */

    private function createIncome(
        MonitoringProduction $production,
        array $incomeRows
    ): void {
        foreach ($incomeRows as $income) {
            $production->income()->create(
                $this->incomePayload(
                    $income
                )
            );
        }
    }

    private function syncIncome(
        MonitoringProduction $production,
        array $incomeRows
    ): void {
        $submittedIds = [];

        foreach ($incomeRows as $income) {
            $incomeId =
                $income['id']
                    ?? null;

            if ($incomeId) {
                if (
                    in_array(
                        $incomeId,
                        $submittedIds,
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'income' =>
                            'The same income record cannot be submitted more than once.',
                    ]);
                }

                $existing =
                    MonitoringIncome::query()
                        ->where(
                            'id',
                            $incomeId
                        )
                        ->where(
                            'monitoring_production_id',
                            $production->id
                        )
                        ->first();

                if (! $existing) {
                    throw ValidationException::withMessages([
                        'income' =>
                            'One of the submitted income records does not belong to this production record.',
                    ]);
                }

                $existing->update(
                    $this->incomePayload(
                        $income
                    )
                );

                $submittedIds[] =
                    $existing->id;

                continue;
            }

            $created =
                $production->income()->create(
                    $this->incomePayload(
                        $income
                    )
                );

            $submittedIds[] =
                $created->id;
        }

        $removed =
            MonitoringIncome::query()
                ->where(
                    'monitoring_production_id',
                    $production->id
                )
                ->when(
                    ! empty($submittedIds),
                    fn ($query) =>
                        $query->whereNotIn(
                            'id',
                            $submittedIds
                        )
                )
                ->get();

        foreach ($removed as $income) {
            $income->delete();
        }
    }

    private function incomePayload(
        array $income
    ): array {
        $periodType =
            $income['period_type'];

        return [
            'period_type' =>
                $periodType,

            'month' =>
                $periodType === 'monthly'
                    ? $income['month']
                    : null,

            'gross_income' =>
                $income['gross_income'],

            'remarks' =>
                $income['remarks']
                    ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MATERIAL MONITORING
    |--------------------------------------------------------------------------
    */

    private function createMaterials(
        MonitoringRecord $record,
        Project $project,
        array $materials
    ): void {
        foreach ($materials as $material) {
            $projectMaterial =
                $this->resolveProjectMaterial(
                    $project,
                    $material[
                        'project_material_id'
                    ]
                );

            $record->materials()->create([
                'project_material_id' =>
                    $projectMaterial->id,

                'condition' =>
                    trim(
                        $material['condition']
                    ),

                'scheduled_maintenance' =>
                    $material[
                        'scheduled_maintenance'
                    ] ?? null,

                'actual_maintenance' =>
                    $material[
                        'actual_maintenance'
                    ] ?? null,

                'remarks' =>
                    $material[
                        'remarks'
                    ] ?? null,
            ]);
        }
    }

    private function syncMaterials(
        MonitoringRecord $record,
        Project $project,
        array $materials
    ): void {
        $submittedIds = [];

        foreach ($materials as $material) {
            $projectMaterial =
                $this->resolveProjectMaterial(
                    $project,
                    $material[
                        'project_material_id'
                    ]
                );

            $monitoringMaterialId =
                $material['id']
                    ?? null;

            if ($monitoringMaterialId) {
                if (
                    in_array(
                        $monitoringMaterialId,
                        $submittedIds,
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'materials' =>
                            'The same monitoring material cannot be submitted more than once.',
                    ]);
                }

                $existing =
                    MonitoringMaterial::query()
                        ->where(
                            'id',
                            $monitoringMaterialId
                        )
                        ->where(
                            'monitoring_record_id',
                            $record->id
                        )
                        ->first();

                if (! $existing) {
                    throw ValidationException::withMessages([
                        'materials' =>
                            'One of the submitted monitoring materials does not belong to this monitoring record.',
                    ]);
                }

                $existing->update([
                    'project_material_id' =>
                        $projectMaterial->id,

                    'condition' =>
                        trim(
                            $material[
                                'condition'
                            ]
                        ),

                    'scheduled_maintenance' =>
                        $material[
                            'scheduled_maintenance'
                        ] ?? null,

                    'actual_maintenance' =>
                        $material[
                            'actual_maintenance'
                        ] ?? null,

                    'remarks' =>
                        $material[
                            'remarks'
                        ] ?? null,
                ]);

                $submittedIds[] =
                    $existing->id;

                continue;
            }

            $created =
                $record->materials()->create([
                    'project_material_id' =>
                        $projectMaterial->id,

                    'condition' =>
                        trim(
                            $material[
                                'condition'
                            ]
                        ),

                    'scheduled_maintenance' =>
                        $material[
                            'scheduled_maintenance'
                        ] ?? null,

                    'actual_maintenance' =>
                        $material[
                            'actual_maintenance'
                        ] ?? null,

                    'remarks' =>
                        $material[
                            'remarks'
                        ] ?? null,
                ]);

            $submittedIds[] =
                $created->id;
        }

        $removed =
            MonitoringMaterial::query()
                ->where(
                    'monitoring_record_id',
                    $record->id
                )
                ->when(
                    ! empty($submittedIds),
                    fn ($query) =>
                        $query->whereNotIn(
                            'id',
                            $submittedIds
                        )
                )
                ->get();

        foreach ($removed as $material) {
            $material->delete();
        }
    }

    private function resolveProjectMaterial(
        Project $project,
        string $materialId
    ): ProjectMaterial {
        $material =
            ProjectMaterial::query()
                ->where(
                    'id',
                    $materialId
                )
                ->where(
                    'project_id',
                    $project->id
                )
                ->first();

        if (! $material) {
            throw ValidationException::withMessages([
                'materials' =>
                    'One of the selected materials does not belong to the selected project.',
            ]);
        }

        return $material;
    }

    /*
    |--------------------------------------------------------------------------
    | EAGER LOADING
    |--------------------------------------------------------------------------
    */

    private function loadMonitoringRecord(
        MonitoringRecord $record
    ): MonitoringRecord {
        return $record->load([
            'project.association',
            'project.products',
            'project.materials',
            'quarter',
            'monitoredBy',
            'createdBy',
            'updatedBy',
            'production.projectProduct',
            'production.income',
            'materials.projectMaterial',
        ]);
    }
}