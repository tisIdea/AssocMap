<?php

namespace App\Services;

use App\Models\Association;
use App\Models\MonitoringIncome;
use App\Models\MonitoringMaterial;
use App\Models\MonitoringProduction;
use App\Models\MonitoringRecord;
use App\Models\MonitoringVisitReport;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectProduct;
use App\Models\Quarter;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Carbon\CarbonImmutable;

class MonitoringService
{
    public function updateOverview(MonitoringRecord $record, array $data, UserProfile $currentUser): void
    {
        DB::transaction(function () use ($record, $data, $currentUser) {
            $record = MonitoringRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->ensureUserCanManageMonitoring($record, $currentUser);
            $project = $this->resolveProjectForUser($record->project_id, $currentUser);
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
        DB::transaction(function () use ($record, $section, $data, $currentUser) {
            $record = MonitoringRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->ensureUserCanManageMonitoring($record, $currentUser);
            $project = $this->resolveProjectForUser($record->project_id, $currentUser);
            if ($section === 'production') {
                $rows = $data['production'] ?? [];
                foreach ($rows as &$row) {
                    unset($row['income']);
                }
                unset($row);
                $this->syncProduction($record, $project, $rows);
            } elseif ($section === 'materials') {
                $this->syncMaterials($record, $project, $data['materials'] ?? []);
            } elseif ($section === 'income') {
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
                    $this->syncIncome($production, $row['income'] ?? []);
                }
            } else {
                abort(404);
            }
            $record->update(['updated_by' => $currentUser->id]
                + ($section === 'production' ? ['project_status' => $data['project_status']] : []));
        }, 3);
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
            $project = Project::query()->where('id', $project->id)->lockForUpdate()->first();
            if (! $project) {
                throw ValidationException::withMessages(['project_id' => 'The selected project is unavailable.']);
            }
            $project = $this->resolveProjectForUser($project->id, $currentUser);
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

            $this->createVisitReport($record, $data, $currentUser);

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
            throw $exception;
        }
    }

    public function createVisitReport(
        MonitoringRecord $record,
        array $data,
        UserProfile $currentUser
    ): MonitoringVisitReport {
        return DB::transaction(function () use ($record, $data, $currentUser) {
            $record = MonitoringRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->ensureUserCanManageMonitoring($record, $currentUser);
            $this->resolveProjectForUser($record->project_id, $currentUser);

            $this->validateVisitDetails($record, $data);
            return $record->visitReports()->create($this->visitReportPayload($data) + [
                'monitored_by' => $currentUser->id,
                'created_by' => $currentUser->id,
                'updated_by' => $currentUser->id,
                'is_legacy_import' => false,
            ]);
        }, 3);
    }

    public function updateVisitReport(
        MonitoringRecord $record,
        string $reportId,
        array $data,
        UserProfile $currentUser
    ): MonitoringVisitReport {
        return DB::transaction(function () use ($record, $reportId, $data, $currentUser) {
            $record = MonitoringRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->ensureUserCanManageMonitoring($record, $currentUser);
            $this->resolveProjectForUser($record->project_id, $currentUser);

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
        if (! array_key_exists('monthly_details', $data) && ! array_key_exists('deleted_monthly_ids', $data)) {
            return;
        }
        $validator = Validator::make($data, [
            'monthly_details' => ['nullable', 'array', 'max:3'],
            'monthly_details.*' => ['array:id,month,activity_count,total_quantity,remarks'],
            'monthly_details.*.id' => ['nullable', 'uuid', 'distinct'],
            'monthly_details.*.month' => ['required', 'integer', 'between:1,12', 'distinct'],
            'monthly_details.*.activity_count' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'monthly_details.*.total_quantity' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'monthly_details.*.remarks' => ['nullable', 'string'],
            'deleted_monthly_ids' => ['array', 'max:3'],
            'deleted_monthly_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) $errors[$prefix.'.'.$field] = $messages;
            throw ValidationException::withMessages($errors);
        }
        $saved = $production->monthlyDetails()->lockForUpdate()->get()->keyBy('id');
        $deleted = $data['deleted_monthly_ids'] ?? [];
        foreach ($deleted as $id) {
            if (! $saved->has($id)) {
                throw ValidationException::withMessages([$prefix.'.deleted_monthly_ids' => 'A monthly record selected for removal does not belong to this Production record.']);
            }
        }
        $quarter = $production->monitoringRecord->quarter()->sharedLock()->firstOrFail();
        $firstMonth = (($quarter->quarter_number - 1) * 3) + 1;
        $months = $saved->except($deleted)->mapWithKeys(fn ($row) => [$row->id => $row->month])->all();
        foreach ($data['monthly_details'] ?? [] as $index => $detail) {
            $id = $detail['id'] ?? null;
            if ($id && (! $saved->has($id) || in_array($id, $deleted, true))) {
                throw ValidationException::withMessages([$prefix.".monthly_details.{$index}.id" => 'This monthly record is unavailable or belongs to another Production record.']);
            }
            $month = (int) $detail['month'];
            if ($month < $firstMonth || $month > $firstMonth + 2) {
                throw ValidationException::withMessages([$prefix.".monthly_details.{$index}.month" => 'Select a Production month within the Monitoring quarter.']);
            }
            if (in_array($month, array_diff_key($months, $id ? [$id => true] : []), true)) {
                throw ValidationException::withMessages([$prefix.".monthly_details.{$index}.month" => 'Only one monthly entry is allowed per Production month.']);
            }
            $months[$id ?? 'new-'.$index] = $month;
        }
        foreach ($deleted as $id) {
            $saved[$id]->delete();
        }
        foreach ($data['monthly_details'] ?? [] as $detail) {
            $payload = array_intersect_key($detail, array_flip(['month', 'activity_count', 'total_quantity', 'remarks']));
            if (array_key_exists('remarks', $payload)) {
                $payload['remarks'] = trim($payload['remarks'] ?? '');
                if ($payload['remarks'] === '') $payload['remarks'] = null;
            }
            if (! empty($detail['id'])) $saved[$detail['id']]->update($payload);
            else $production->monthlyDetails()->create($payload);
        }
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

        return DB::transaction(function () use (
            $record,
            $data,
            $currentUser,
            $project
        ) {
            $record = MonitoringRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->ensureUserCanManageMonitoring($record, $currentUser);
            $this->resolveProjectForUser($record->project_id, $currentUser);
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
        }, 3);
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
            $productionRows as $index => $production
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

            $this->syncMonthlyDetails($productionRecord, $production, "production.{$index}");
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
            $productionRows as $index => $production
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
                        ->lockForUpdate()
                        ->first();

                if (! $existing) {
                    throw ValidationException::withMessages([
                        'production' =>
                            'One of the submitted production records does not belong to this monitoring record.',
                    ]);
                }

                $unit = trim($production['unit']);
                $identityChanges = [];
                if ((string) $existing->project_product_id !== (string) $product->id) {
                    $identityChanges['project_product_id'] = $product->id;
                }
                if ((string) $existing->unit !== $unit) {
                    $identityChanges['unit'] = $unit;
                }
                if ($identityChanges && $existing->monthlyDetails()->exists()) {
                    $errors = [];
                    foreach (array_keys($identityChanges) as $field) {
                        $errors["production.{$index}.{$field}"] = 'Product and unit cannot change while saved monthly Production details exist. Save explicit monthly removals first.';
                    }
                    throw ValidationException::withMessages($errors);
                }

                // Save permitted identity changes before monthly details protect the parent.
                $existing->update($identityChanges + [
                    'target_output' =>
                        $production[
                            'target_output'
                        ],

                    'actual_output' =>
                        $production[
                            'actual_output'
                        ],

                    'remarks' =>
                        $production[
                            'remarks'
                        ] ?? null,
                ]);
                $this->syncMonthlyDetails($existing, $production, "production.{$index}");

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

            $this->syncMonthlyDetails($created, $production, "production.{$index}");
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
            if ($production->monthlyDetails()->exists()) {
                throw ValidationException::withMessages(['production' => 'Remove this product’s saved monthly details explicitly and save before removing its quarterly Production entry.']);
            }
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
            'production.monthlyDetails',
            'materials.projectMaterial',
            'visitReports.conductedByUser:id,display_name',
        ]);
    }
}
