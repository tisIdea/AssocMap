<?php

namespace App\Services\Monitoring;

use App\Models\Association;
use App\Models\MonitoringIncome;
use App\Models\MonitoringProduction;
use App\Models\MonitoringRecord;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectProduct;
use App\Models\Quarter;
use App\Models\UserProfile;
use App\Services\Associations\AssociationLifecycleService;
use App\Services\Programs\ProgramActorService;
use App\Services\Projects\ProjectLifecycleService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;

class MonitoringService
{
    public function __construct(private AssociationLifecycleService $lifecycle, private ProjectLifecycleService $projects,
        private ProductionMonitoringService $production, private IncomeMonitoringService $income,
        private MaterialsMonitoringService $materials, private ProgramActorService $actors) {}

    public function updateOverview(MonitoringRecord $record, array $data, UserProfile $currentUser): void
    {
        DB::transaction(function () use ($record, $data, $currentUser) {
            $record = $this->lockRecordForMutation($record, $currentUser);
            $project = $record->project;
            $userId = $data['monitored_by'] ?? null;
            if ($userId !== $record->monitored_by) {
                $user = UserProfile::with('role')->whereKey($userId)->sharedLock()->first();
                $association = $project->association()->sharedLock()->first();
                $eligible = $user?->is_active && $user->role?->is_active
                    && ($user->role->code === 'system_admin'
                        || ($user->role->code === 'field_officer' && $association?->primary_field_officer_id === $userId));
                if (! $eligible) {
                    throw ValidationException::withMessages(['monitored_by' => 'Select an active administrator or this association\'s assigned active Field Officer.']);
                }
            }
            $record->update(['monitored_by' => $userId, 'site_validated' => (bool) $data['site_validated']]);
        }, 3);
    }

    public function updateSection(MonitoringRecord $record, string $section, array $data, UserProfile $currentUser): void
    {
        try {
            DB::transaction(function () use ($record, $section, $data, $currentUser) {
                $record = $this->lockRecordForMutation($record, $currentUser);
                $project = $record->project;
                if ($section === 'production') {
                    $this->production->assertRevision($record, $data);
                    $rows = $data['production'] ?? [];
                    foreach ($rows as &$row) {
                        unset($row['income'], $row['deleted_income_ids']);
                    }
                    unset($row);
                    $this->syncProduction($record, $project, $rows);
                } elseif ($section === 'materials') {
                    $this->materials->assertRevision($record, $data);
                    $this->syncMaterials($record, $project, $data['materials'] ?? [], $data['deleted_material_ids'] ?? []);
                    $record->increment('materials_version');
                } elseif ($section === 'income') {
                    $this->income->assertRevision($record, $data);
                    $submitted = [];
                    foreach ($data['production'] ?? [] as $index => $row) {
                        $id = $row['id'] ?? null;
                        if (! $id || in_array($id, $submitted, true)) {
                            throw ValidationException::withMessages(["production.{$index}.id" => 'Income requires a unique saved Production record. Save Production first.']);
                        }
                        $production = $record->production()->whereKey($id)->lockForUpdate()->first();
                        if (! $production || $production->project_product_id !== $row['project_product_id']) {
                            throw ValidationException::withMessages(["production.{$index}.id" => 'This Production record is unavailable.']);
                        }
                        $submitted[] = $id;
                        $this->syncIncome($production, $row['income'] ?? [], $row['deleted_income_ids'] ?? []);
                    }
                    $record->increment('income_version');
                } else {
                    abort(404);
                }
                $record->update(['updated_by' => $currentUser->id]
                    + ($section === 'production' ? ['project_status' => $data['project_status']] : []));
            }, 3);
        } catch (QueryException $exception) {
            if ($section === 'materials') $this->materialsWriteFailure($exception);
            if ($section === 'income') $this->incomeWriteFailure($exception);
            if ($section === 'production') $this->productionWriteFailure($exception);
            throw $exception;
        }
    }

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

        try {
            return DB::transaction(function () use (
            $data,
            $currentUser,
            $project
        ) {
            $project = $this->lockProjectForWrite($project, $currentUser);
            $this->ensureMonitoringPeriodIsUnique($project, (int) $data['year'], (int) $data['quarter_id']);
            $data = $this->resolveCreationResources($project, $data, $currentUser);

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

            $this->persistVisitReport($record, $data, $currentUser);

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
            }, 3);
        } catch (QueryException $exception) {
            $diagnostic = $exception->errorInfo[2] ?? null;
            $constraint = is_string($diagnostic) && preg_match(
                '/\A(?:ERROR:\s*)?duplicate key value violates unique constraint "([^"]+)"(?:\r?\n|\z)/',
                $diagnostic, $matches
            ) === 1 ? $matches[1] : null;
            if (($exception->errorInfo[0] ?? null) === '23505'
                && $constraint === 'monitoring_records_project_id_year_quarter_id_key') {
                throw ValidationException::withMessages(['quarter_id' => 'A monitoring record already exists for this project, year, and quarter.']);
            }
            if (($exception->errorInfo[0] ?? null) === '23505' && str_contains($exception->getMessage(), 'uq_project_product_name_ci')) {
                throw ValidationException::withMessages(['production' => 'A proposed product name now exists under this project. Select the existing product instead.']);
            }
            if (! empty($data['production'])) $this->productionWriteFailure($exception);
            throw $exception;
        }
    }

    public function createVisitReport(
        MonitoringRecord $record,
        array $data,
        UserProfile $currentUser
    ): MonitoringVisitReport {
        return DB::transaction(function () use ($record, $data, $currentUser) {
            $record = $this->lockRecordForMutation($record, $currentUser);

            return $this->persistVisitReport($record, $data, $currentUser);
        }, 3);
    }

    private function persistVisitReport(MonitoringRecord $record, array $data, UserProfile $currentUser): MonitoringVisitReport
    {
        $this->validateVisitDetails($record, $data);

        return $record->visitReports()->create($this->visitReportPayload($data) + [
            'monitored_by' => $currentUser->id,
            'created_by' => $currentUser->id,
            'updated_by' => $currentUser->id,
            'is_legacy_import' => false,
        ]);
    }

    public function updateVisitReport(
        MonitoringRecord $record,
        string $reportId,
        array $data,
        UserProfile $currentUser
    ): MonitoringVisitReport {
        return DB::transaction(function () use ($record, $reportId, $data, $currentUser) {
            $record = $this->lockRecordForMutation($record, $currentUser);

            $report = $record->visitReports()->whereKey($reportId)->lockForUpdate()->firstOrFail();
            $this->validateVisitDetails($record, $data, $report);
            $report->update($this->visitReportPayload($data, true) + [
                'updated_by' => $currentUser->id,
            ]);

            return $report->refresh();
        }, 3);
    }

    public function getVisitReports(MonitoringRecord $record, UserProfile $currentUser): Collection
    {
        $this->ensureUserCanManageMonitoring($record, $currentUser);
        if ($currentUser->role?->code === 'field_officer') {
            $this->resolveProjectForUser($record->project_id, $currentUser);
        }

        return $record->visitReports()->with('conductedByUser:id,display_name')->get();
    }

    private function visitReportPayload(array $data, bool $partial = false): array
    {
        $payload = [
            'monitoring_date' => $data['monitoring_date'],
            'site_validated' => (bool) $data['site_validated'],
        ];

        foreach (['issues_encountered', 'actions_taken', 'overall_remarks', 'conducted_by_user_id', 'conducted_by'] as $field) {
            if (! $partial || array_key_exists($field, $data)) {
                $value = $data[$field] ?? null;
                $payload[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }

        return $payload;
    }

    private function validateVisitDetails(MonitoringRecord $record, array $data, ?MonitoringVisitReport $report = null): void
    {
        Validator::make($data, [
            'monitoring_date' => ['required', 'date'],
            'site_validated' => ['required', 'boolean'],
            'conducted_by_user_id' => ['nullable', 'uuid'],
            'conducted_by' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $date = CarbonImmutable::parse($data['monitoring_date'])->format('Y-m-d');
        if (! $report || $date !== $report->monitoring_date->format('Y-m-d')) {
            $quarter = $this->resolveQuarter((int) $record->quarter_id);
            $start = CarbonImmutable::create((int) $record->year, (($quarter->quarter_number - 1) * 3) + 1, 1)->startOfDay();
            if ($date < $start->format('Y-m-d') || $date > $start->addMonths(3)->subDay()->format('Y-m-d')) {
                throw ValidationException::withMessages(['monitoring_date' => 'Select a visit date within the Monitoring year and quarter.']);
            }
        }

        $userId = array_key_exists('conducted_by_user_id', $data) ? ($data['conducted_by_user_id'] ?: null) : $report?->conducted_by_user_id;
        $name = array_key_exists('conducted_by', $data) ? trim($data['conducted_by'] ?? '') : $report?->conducted_by;
        if ($name === '') $name = null;
        if ($report && $userId === $report->conducted_by_user_id && $name === $report->conducted_by) {
            return;
        }
        if (($userId === null) === ($name === null)) {
            throw ValidationException::withMessages(['conducted_by' => 'Select the assigned Field Officer or enter another person’s name, using exactly one option.']);
        }
        if ($userId !== null) {
            $association = $record->project->association()->sharedLock()->first();
            $officer = UserProfile::with('role')->whereKey($userId)->sharedLock()->first();
            if (! $association || $association->primary_field_officer_id !== $userId || ! $officer?->is_active || ! $officer->role?->is_active || $officer->role?->code !== 'field_officer') {
                throw ValidationException::withMessages(['conducted_by_user_id' => 'Select the association’s assigned active Field Officer.']);
            }
        }
    }

    private function syncMonthlyDetails(MonitoringProduction $production, array $data, string $prefix): void
    {
        $record = $production->monitoringRecord;
        $record->setRelation('quarter', $record->quarter()->sharedLock()->firstOrFail());
        $quarterNumber = $record->quarter->quarter_number;
        $details = $data['monthly_details'] ?? [];
        $this->production->calculateActualOutput($record->year, $quarterNumber, $details, $prefix);
        if (! empty($data['deleted_monthly_ids'])) {
            throw ValidationException::withMessages([$prefix.'.monthly_details' => 'All three months must be retained. Monthly Production cannot be deleted.']);
        }
        $saved = $production->monthlyDetails()->lockForUpdate()->get()->keyBy('id');
        foreach ($details as $index => $detail) {
            $id = $detail['id'] ?? null;
            if (($id && (! $saved->has($id) || $saved[$id]->month !== (int) $detail['month']))
                || (! $id && $saved->contains('month', (int) $detail['month']))) {
                throw ValidationException::withMessages([$prefix.".monthly_details.{$index}.id" => 'Reload Production. This month must keep its saved identity.'])->status(409);
            }
            $payload = array_intersect_key($detail, array_flip(['month', 'activity_count', 'total_quantity', 'remarks']));
            $payload['remarks'] = isset($payload['remarks']) ? (trim($payload['remarks']) ?: null) : null;
            if ($id) $saved[$id]->update($payload);
            else $production->monthlyDetails()->create($payload);
        }
        $persisted = $production->monthlyDetails()->lockForUpdate()->get()->map(fn ($row) => $row->only([
            'id', 'month', 'activity_count', 'total_quantity', 'remarks',
        ]))->all();
        $production->update(['actual_output' => $this->production->calculateActualOutput(
            $record->year, $quarterNumber, $persisted, $prefix
        )]);
    }

    private function resolveCreationResources(Project $project, array $data, UserProfile $currentUser): array
    {
        $projectService = app(ProjectService::class);
        $names = [];
        $productIds = [];
        foreach ($data['production'] ?? [] as $index => $production) {
            if (! empty($production['id'])) {
                throw ValidationException::withMessages(["production.{$index}.id" => 'Saved production IDs cannot be used when adding Monitoring.']);
            }
            $new = isset($production['new_product']);
            $existing = ! empty($production['project_product_id']);
            if ($new === $existing) {
                throw ValidationException::withMessages(["production.{$index}.project_product_id" => 'Select an existing product or register a new product, using exactly one option.']);
            }
            if ($new) {
                $name = $production['new_product']['product_name'] ?? '';
                $key = mb_strtolower(trim($name));
                if (isset($names[$key])) {
                    throw ValidationException::withMessages(["production.{$index}.new_product.product_name" => 'The same new product cannot be registered twice in this submission.']);
                }
                $names[$key] = true;
                $product = $projectService->registerProduct($project, $production['new_product'], $currentUser, "production.{$index}.new_product");
            } else {
                $product = $this->resolveProjectProduct($project, $production['project_product_id']);
            }
            if (isset($productIds[$product->id])) {
                throw ValidationException::withMessages(["production.{$index}.project_product_id" => 'Select each project product only once.']);
            }
            $productIds[$product->id] = true;
            $data['production'][$index]['project_product_id'] = $product->id;
            unset($data['production'][$index]['new_product']);
        }

        $materialIds = [];
        foreach ($data['materials'] ?? [] as $index => $material) {
            if (! empty($material['id'])) {
                throw ValidationException::withMessages(["materials.{$index}.id" => 'Saved observation IDs cannot be used when adding Monitoring.']);
            }
            $new = isset($material['new_material']);
            $existing = ! empty($material['project_material_id']);
            if ($new === $existing) {
                throw ValidationException::withMessages(["materials.{$index}.project_material_id" => 'Select an existing material or register a new material, using exactly one option.']);
            }
            $resource = $new
                ? $projectService->registerMaterial($project, $material['new_material'], $currentUser, "materials.{$index}.new_material")
                : $this->resolveProjectMaterial($project, $material['project_material_id']);
            if (isset($materialIds[$resource->id])) {
                throw ValidationException::withMessages(["materials.{$index}.project_material_id" => 'Select each project material only once.']);
            }
            $materialIds[$resource->id] = true;
            $data['materials'][$index]['project_material_id'] = $resource->id;
            unset($data['materials'][$index]['new_material']);
        }

        return $data;
    }

    public function update(
        MonitoringRecord $record,
        array $data,
        UserProfile $currentUser
    ): MonitoringRecord {
        foreach (['production' => 'new_product', 'materials' => 'new_material'] as $collection => $payload) {
            foreach ($data[$collection] ?? [] as $row) {
                if (isset($row[$payload])) {
                    throw ValidationException::withMessages([$collection => 'Inline registration is available only when adding Monitoring.']);
                }
            }
        }

        $this->ensureUserCanManageMonitoring(
            $record,
            $currentUser
        );

        $identityErrors = [];
        foreach (['project_id' => 'Project', 'year' => 'Year', 'quarter_id' => 'Quarter'] as $field => $label) {
            if (! array_key_exists($field, $data) || ! is_scalar($data[$field])
                || (string) $data[$field] !== (string) $record->getAttribute($field)) {
                $identityErrors[$field] = "{$label} cannot be changed after the Monitoring record has been created.";
            }
        }
        if ($identityErrors) {
            throw ValidationException::withMessages($identityErrors);
        }

        $project = $this->resolveProjectForUser(
            $record->project_id,
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

        try {
            return DB::transaction(function () use (
                $record,
                $data,
                $currentUser
            ) {
                $record = $this->lockRecordForMutation($record, $currentUser);
                $project = $record->project;
                $changesMaterials = $this->materials->includesEdits($data);
                if ($changesMaterials) $this->materials->assertRevision($record, $data);
                $changesIncome = $this->income->includesEdits($data['production'] ?? []);
                if ($changesIncome) $this->income->assertRevision($record, $data);
                if (array_key_exists('production', $data)) $this->production->assertRevision($record, $data);
                $record->update([
                    'project_status' =>
                        $data['project_status'],

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
                    if ($changesIncome) $record->increment('income_version');
                }

                if ($changesMaterials) {
                    $this->syncMaterials(
                        $record,
                        $project,
                        $data['materials'] ?? [],
                        $data['deleted_material_ids'] ?? []
                    );
                    $record->increment('materials_version');
                }

                return $this->loadMonitoringRecord(
                    $record
                );
            }, 3);
        } catch (QueryException $exception) {
            if ($this->materials->includesEdits($data)) $this->materialsWriteFailure($exception);
            if ($this->income->includesEdits($data['production'] ?? [])) $this->incomeWriteFailure($exception);
            if (array_key_exists('production', $data)) $this->productionWriteFailure($exception);
            throw $exception;
        }
    }

    private function materialsWriteFailure(QueryException $exception): never
    {
        Log::warning('Monitoring Materials write failed.', ['code' => 'materials_write_failed', 'sqlstate' => $exception->errorInfo[0] ?? null]);
        throw ValidationException::withMessages(['materials' => 'Materials could not be confirmed as saved. Keep your draft and review the latest saved Materials before retrying.'])->status(503);
    }

    private function incomeWriteFailure(QueryException $exception): never
    {
        Log::warning('Monitoring Income write failed.', ['code' => 'income_write_failed', 'sqlstate' => $exception->errorInfo[0] ?? null]);
        throw ValidationException::withMessages(['income' => 'Income could not be confirmed as saved. Keep your draft and review the latest saved Income before retrying.'])->status(503);
    }

    private function productionWriteFailure(QueryException $exception): never
    {
        Log::warning('Production Monitoring write failed.', ['code' => 'production_write_failed',
            'sqlstate' => $exception->errorInfo[0] ?? null]);
        throw ValidationException::withMessages(['production' => 'Production could not be saved. Reload and review the record before retrying, or contact the administrator.'])->status(503);
    }

    /*
    |--------------------------------------------------------------------------
    | PROJECT / ROLE AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    private function lockProjectForWrite(Project $project, UserProfile $actor): Project
    {
        $fresh = $this->actors->lock($actor);
        $this->lifecycle->lockOperational([$project->association_id]);
        $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        if ($locked->association_id !== $project->association_id) {
            throw ValidationException::withMessages(['project_id' => 'The project association changed. Refresh before saving.'])->status(409);
        }
        $this->projects->assertNotFinalized($locked);

        return $this->resolveProjectForUser($locked->id, $fresh);
    }

    public function lockRecordForMutation(MonitoringRecord $record, UserProfile $actor): MonitoringRecord
    {
        $project = Project::whereKey($record->project_id)->firstOrFail();
        $project = $this->lockProjectForWrite($project, $actor);
        $locked = MonitoringRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
        if ($locked->project_id !== $record->project_id) {
            throw ValidationException::withMessages(['monitoring' => 'The monitoring project changed. Refresh before saving.'])->status(409);
        }

        return $locked->setRelation('project', $project);
    }

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

    private function createProduction(MonitoringRecord $record, Project $project, array $productionRows): void
    {
        $this->syncProduction($record, $project, $productionRows);
    }

    private function syncProduction(MonitoringRecord $record, Project $project, array $productionRows): void
    {
        $submittedIds = [];
        $productIds = [];
        foreach ($productionRows as $index => $row) {
            $prefix = "production.{$index}";
            if (isset($row['quantity_source']) && $row['quantity_source'] !== 'aggregate') {
                throw ValidationException::withMessages([$prefix => 'Initialize daily harvest through the daily harvest workflow.']);
            }
            $product = $this->resolveProjectProduct($project, $row['project_product_id']);
            if (isset($productIds[$product->id])) {
                throw ValidationException::withMessages([$prefix.'.project_product_id' => 'Select each project product only once.']);
            }
            $productIds[$product->id] = true;
            $id = $row['id'] ?? null;
            $existing = null;
            if ($id) {
                if (isset($submittedIds[$id])) {
                    throw ValidationException::withMessages([$prefix.'.id' => 'Submit each Production record only once.']);
                }
                $submittedIds[$id] = true;
                $existing = $record->production()->whereKey($id)->lockForUpdate()->first();
                if (! $existing || $existing->project_product_id !== $product->id) {
                    throw ValidationException::withMessages([$prefix.'.id' => 'This Production record or product is unavailable.']);
                }
                if ($existing->quantity_source === 'daily_harvest') {
                    if (array_diff(array_keys($row), ['id', 'project_product_id', 'income', 'deleted_income_ids'])) {
                        throw ValidationException::withMessages([$prefix => 'Daily harvest quantities are calculated. Use Daily Harvest for entries and quarterly goals.']);
                    }
                    if (array_key_exists('income', $row) || array_key_exists('deleted_income_ids', $row)) {
                        $this->syncIncome($existing, $row['income'] ?? [], $row['deleted_income_ids'] ?? []);
                    }
                    continue;
                }
                if (! $this->production->canEditMonthly($existing)) {
                    if (array_diff(array_keys($row), ['id', 'project_product_id', 'income', 'deleted_income_ids'])) {
                        throw ValidationException::withMessages([$prefix => 'Legacy quarterly-only or unreconciled Production is preserved read-only. A separate conversion decision is required.']);
                    }
                    if (array_key_exists('income', $row) || array_key_exists('deleted_income_ids', $row)) {
                        $this->syncIncome($existing, $row['income'] ?? [], $row['deleted_income_ids'] ?? []);
                    }
                    continue;
                }
            } elseif ($record->production()->where('project_product_id', $product->id)->exists()) {
                throw ValidationException::withMessages([$prefix.'.project_product_id' => 'This product already has Production. Reload before saving.'])->status(409);
            }
            $validator = Validator::make($row, [
                'target_output' => ['required', 'regex:/\\A\\d{1,12}(?:\\.\\d{1,2})?\\z/D'],
                'unit' => ['required', 'string', 'max:50'],
                'remarks' => ['nullable', 'string'],
            ]);
            if ($validator->fails()) {
                $errors = [];
                foreach ($validator->errors()->messages() as $field => $messages) $errors[$prefix.'.'.$field] = $messages;
                throw ValidationException::withMessages($errors);
            }
            $unit = trim($row['unit']);
            if ($existing && $existing->unit !== $unit) {
                throw ValidationException::withMessages([$prefix.'.unit' => 'Saved Production must retain its measurement unit.']);
            }
            $total = $this->production->calculateActualOutput($record->year, $record->quarter->quarter_number,
                $row['monthly_details'] ?? [], $prefix);
            $payload = ['target_output' => $row['target_output'], 'unit' => $unit,
                'remarks' => $row['remarks'] ?? null, 'actual_output' => $total];
            $isNew = $existing === null;
            if ($existing) $existing->update($payload);
            else $existing = $record->production()->create(['project_product_id' => $product->id] + $payload);
            $this->syncMonthlyDetails($existing, $row, $prefix);
            if (array_key_exists('income', $row) || array_key_exists('deleted_income_ids', $row)) {
                if ($isNew && ! empty($row['deleted_income_ids'])) {
                    throw ValidationException::withMessages([$prefix.'.deleted_income_ids' => 'New Production has no saved Income to remove.']);
                }
                if ($isNew) $this->createIncome($existing, $row['income'] ?? []);
                else $this->syncIncome($existing, $row['income'] ?? [], $row['deleted_income_ids'] ?? []);
            }
        }
        // Omitted products retain their Production, monthly and Income history.
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
        array $incomeRows,
        array $removedIds = []
    ): void {
        $saved = $production->income()->lockForUpdate()->get()->keyBy('id');
        $validator = Validator::make(['removed' => $removedIds], [
            'removed' => ['array'], 'removed.*' => ['required', 'uuid', 'distinct'],
        ]);
        if ($validator->fails() || array_diff($removedIds, $saved->keys()->all())) {
            throw ValidationException::withMessages(['income' => 'Remove only saved Income entries belonging to this Production record.']);
        }
        $this->validateIncomeState($production, $saved, $incomeRows, $removedIds);
        foreach ($incomeRows as $income) {
            if (isset($income['id']) && in_array($income['id'], $removedIds, true)) {
                throw ValidationException::withMessages(['income' => 'An Income entry cannot be edited and removed in the same submission.']);
            }
        }
        // Omission preserves saved entries; only explicit scoped identifiers request deletion.
        foreach ($removedIds as $id) $saved[$id]->delete();
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

    }

    private function validateIncomeState(MonitoringProduction $production, Collection $saved, array $rows, array $removedIds): void
    {
        $validator = Validator::make(['income' => $rows], [
            'income.*' => ['array'], 'income.*.id' => ['nullable', 'uuid', 'distinct'],
            'income.*.period_type' => ['required', \Illuminate\Validation\Rule::in(['monthly', 'quarterly'])],
            'income.*.month' => ['nullable', 'integer', 'between:1,12'],
            'income.*.gross_income' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'income.*.remarks' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) throw new ValidationException($validator);
        $final = $saved->toBase()->except($removedIds)->map(fn ($income) => $income->only(['period_type', 'month', 'gross_income', 'remarks']))->all();
        foreach ($rows as $index => $income) {
            $id = $income['id'] ?? null;
            if ($id && ! $saved->has($id)) {
                throw ValidationException::withMessages(['income' => 'One of the submitted Income records does not belong to this Production record.']);
            }
            $final[$id ?: 'new-'.$index] = $income;
        }
        $months = $this->production->monthsForQuarter($production->monitoringRecord->quarter->quarter_number);
        $periods = []; $slots = [];
        foreach ($final as $income) {
            $period = $income['period_type'];
            $month = $income['month'] ?? null;
            $slot = $period === 'quarterly' ? 'quarter' : 'month-'.(int) $month;
            $periods[$period] = true;
            if (count($periods) > 1 || isset($slots[$slot])
                || ($period === 'quarterly' && $month !== null && $month !== '')
                || ($period === 'monthly' && ! in_array((int) $month, $months, true))) {
                throw ValidationException::withMessages(['income' => 'Retained and submitted Income must use one frequency, with one quarterly entry or distinct months within the Monitoring quarter. Remove entries explicitly when necessary.']);
            }
            $slots[$slot] = true;
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
        array $materials,
        array $removedIds = []
    ): void {
        Validator::make(['materials' => $materials, 'deleted_material_ids' => $removedIds], [
            'materials.*' => ['array'],
            'materials.*.id' => ['nullable', 'uuid', 'distinct'],
            'materials.*.project_material_id' => ['required', 'uuid'],
            'materials.*.condition' => ['required', 'string', 'max:50'],
            'materials.*.scheduled_maintenance' => ['nullable', 'date'],
            'materials.*.actual_maintenance' => ['nullable', 'date'],
            'materials.*.remarks' => ['nullable', 'string'],
            'deleted_material_ids.*' => ['required', 'uuid', 'distinct'],
        ])->validate();

        $saved = $record->materials()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (array_diff($removedIds, $saved->modelKeys())) {
            throw ValidationException::withMessages(['deleted_material_ids' => 'Remove only saved observations belonging to this Monitoring record.']);
        }
        $final = $saved->toBase()->except($removedIds)->map(fn ($row) => $row->project_material_id)->all();
        foreach ($materials as $index => $material) {
            $id = $material['id'] ?? null;
            if ($id && (! $saved->has($id) || in_array($id, $removedIds, true))) {
                throw ValidationException::withMessages(["materials.{$index}.id" => 'The observation is unavailable or is also selected for removal.']);
            }
            $this->resolveProjectMaterial($project, $material['project_material_id']);
            $final[$id ?: 'new-'.$index] = $material['project_material_id'];
        }
        if (count($final) !== count(array_unique($final))) {
            throw ValidationException::withMessages(['materials' => 'Each project material may have only one observation, including retained observations.']);
        }

        foreach ($removedIds as $id) $saved->get($id)->delete();
        foreach ($materials as $material) {
            $payload = [
                'project_material_id' => $material['project_material_id'],
                'condition' => trim($material['condition']),
                'scheduled_maintenance' => $material['scheduled_maintenance'] ?? null,
                'actual_maintenance' => $material['actual_maintenance'] ?? null,
                'remarks' => $material['remarks'] ?? null,
            ];
            if (! empty($material['id'])) $saved->get($material['id'])->update($payload);
            else $record->materials()->create($payload);
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
            'production.monthlyDetails',
            'materials.projectMaterial',
            'visitReports.conductedByUser:id,display_name',
        ]);
    }
}
