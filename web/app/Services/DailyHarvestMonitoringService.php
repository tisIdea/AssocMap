<?php

namespace App\Services;

use App\Models\MonitoringProduction;
use App\Models\MonitoringRecord;
use App\Models\ProjectProduct;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PDOException;

class DailyHarvestMonitoringService
{
    private const ENTRY_FIELDS = ['monitoring_production_id', 'harvest_date', 'quantity', 'remarks',
        'created_by', 'updated_by', 'voided_at', 'voided_by', 'void_reason'];

    public function __construct(private MonitoringService $monitoring,
        private ProductionMonitoringService $production, private AuditLogService $audit) {}

    public function initialize(MonitoringRecord $record, Request $request): MonitoringProduction
    {
        return $this->write($record, $request, function ($record, $actor) use ($request) {
            $data = Validator::make($request->all(), [
                'project_product_id' => ['required', 'uuid'],
                'actual_output' => ['prohibited'], 'monthly_details' => ['prohibited'],
                'target_output' => ['prohibited'], 'unit' => ['prohibited'],
            ])->validate();
            $product = ProjectProduct::whereKey($data['project_product_id'])
                ->where('project_id', $record->project_id)->where('is_active', true)->sharedLock()->first();
            if (! $product || trim((string) $product->default_unit) === '') {
                throw ValidationException::withMessages(['project_product_id' => 'Select an active product with a measurement unit from this project.']);
            }
            if ($record->production()->where('project_product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['project_product_id' => 'This product already has Production for this quarter. Existing aggregate records cannot be converted.'])->status(409);
            }
            $row = $record->production()->create(['project_product_id' => $product->id,
                'quantity_source' => 'daily_harvest', 'unit' => $product->default_unit,
                'target_output' => null, 'actual_output' => '0.00']);
            $this->recalculate($record, $row);
            $this->audit->record($request, 'INITIALIZE DAILY HARVEST', 'Monitoring', 'monitoring_daily_production',
                $row->id, null, $row->only(['monitoring_record_id', 'project_product_id', 'quantity_source', 'unit']),
                ['monitoring_record_id', 'project_product_id', 'quantity_source', 'unit']);

            return $row->fresh();
        });
    }

    public function saveHarvest(MonitoringRecord $record, string $productionId, Request $request, ?string $entryId = null): void
    {
        $this->write($record, $request, function ($record, $actor) use ($productionId, $request, $entryId) {
            $row = $this->dailyRow($record, $productionId);
            $data = $this->validateHarvest($record, $request->all());
            $entry = $entryId ? $row->harvestEntries()->whereKey($entryId)->lockForUpdate()->firstOrFail() : null;
            if ($entry?->voided_at !== null) {
                throw ValidationException::withMessages(['harvest_date' => 'Voided entries cannot be edited.']);
            }
            $duplicate = $row->harvestEntries()->whereNull('voided_at')->whereDate('harvest_date', $data['harvest_date']);
            if ($entry) $duplicate->where('id', '<>', $entry->id);
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['harvest_date' => 'One active entry is allowed per product and date. Edit the existing combined daily quantity.'])->status(409);
            }
            $before = $entry?->only(self::ENTRY_FIELDS);
            $payload = $data + ['updated_by' => $actor->id];
            if ($entry) $entry->update($payload);
            else $entry = $row->harvestEntries()->create($payload + ['created_by' => $actor->id]);
            $this->recalculate($record, $row);
            $row->increment('harvest_version');
            $this->audit->record($request, $entryId ? 'EDIT DAILY HARVEST' : 'CREATE DAILY HARVEST', 'Monitoring',
                'monitoring_harvest_entry', $entry->id, $before, $entry->fresh()->only(self::ENTRY_FIELDS), self::ENTRY_FIELDS);
        });
    }

    public function voidHarvest(MonitoringRecord $record, string $productionId, string $entryId, Request $request): void
    {
        $this->write($record, $request, function ($record, $actor) use ($productionId, $entryId, $request) {
            $data = Validator::make($request->all(), ['void_reason' => ['required', 'string', 'max:2000']])->validate();
            $reason = trim($data['void_reason']);
            if ($reason === '') throw ValidationException::withMessages(['void_reason' => 'A void reason is required.']);
            $row = $this->dailyRow($record, $productionId);
            $entry = $row->harvestEntries()->whereKey($entryId)->lockForUpdate()->firstOrFail();
            if ($entry->voided_at !== null) {
                throw ValidationException::withMessages(['void_reason' => 'This entry is already voided. Reload its history.'])->status(409);
            }
            $before = $entry->only(self::ENTRY_FIELDS);
            $entry->update(['voided_at' => now(), 'voided_by' => $actor->id,
                'void_reason' => $reason, 'updated_by' => $actor->id]);
            $this->recalculate($record, $row);
            $row->increment('harvest_version');
            $this->audit->record($request, 'VOID DAILY HARVEST', 'Monitoring', 'monitoring_harvest_entry',
                $entry->id, $before, $entry->fresh()->only(self::ENTRY_FIELDS), self::ENTRY_FIELDS);
        });
    }

    public function reviseGoal(MonitoringRecord $record, string $productionId, Request $request): void
    {
        $this->write($record, $request, function ($record) use ($productionId, $request) {
            $data = Validator::make($request->all(), [
                'target_output' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/D'],
                'reason' => ['required', 'string', 'max:2000'],
            ])->validate();
            $reason = trim($data['reason']);
            if ($reason === '') throw ValidationException::withMessages(['reason' => 'Explain why this quarterly goal is being set or revised.']);
            $row = $this->dailyRow($record, $productionId);
            $old = ['target_output' => $row->target_output];
            $target = $this->production->sumQuantities([$data['target_output']], 'target_output');
            if ($target === $row->target_output) {
                throw ValidationException::withMessages(['target_output' => 'Enter a different quarterly goal.']);
            }
            $row->update(['target_output' => $target]);
            $row->increment('harvest_version');
            $this->audit->record($request, 'REVISE QUARTERLY PRODUCTION GOAL', 'Monitoring', 'monitoring_production_goal',
                $row->id, $old, ['target_output' => $target, 'reason' => $reason], ['target_output', 'reason']);
        });
    }

    public function validateHarvest(MonitoringRecord $record, array $input): array
    {
        $data = Validator::make($input, [
            'harvest_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('Asia/Manila')->toDateString()],
            'quantity' => ['required', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/D'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'actual_output' => ['prohibited'], 'monthly_details' => ['prohibited'],
            'activity_count' => ['prohibited'], 'total_quantity' => ['prohibited'],
            'target_output' => ['prohibited'], 'unit' => ['prohibited'],
        ])->validate();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['harvest_date'], 'Asia/Manila');
        if ((int) $date->year !== (int) $record->year
            || ! in_array((int) $date->month, $this->production->monthsForQuarter($record->quarter->quarter_number), true)) {
            throw ValidationException::withMessages(['harvest_date' => 'Harvest date must belong to this Monitoring year and quarter.']);
        }
        $data['quantity'] = $this->production->sumQuantities([$data['quantity']]);
        if ($data['quantity'] === '0.00') throw ValidationException::withMessages(['quantity' => 'Harvest quantity must be greater than zero.']);
        $data['remarks'] = isset($data['remarks']) ? (trim($data['remarks']) ?: null) : null;

        return array_intersect_key($data, array_flip(['harvest_date', 'quantity', 'remarks']));
    }

    private function dailyRow(MonitoringRecord $record, string $id): MonitoringProduction
    {
        $row = $record->production()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($row->quantity_source !== 'daily_harvest') {
            throw ValidationException::withMessages(['production' => 'Existing aggregate records retain their aggregate workflow.']);
        }
        if (! $row->projectProduct()->where('project_id', $record->project_id)->sharedLock()->exists()) {
            throw ValidationException::withMessages(['production' => 'This product no longer belongs to the monitored project. Reload before saving.'])->status(409);
        }
        return $row;
    }

    private function recalculate(MonitoringRecord $record, MonitoringProduction $row): void
    {
        $entries = $row->harvestEntries()->whereNull('voided_at')->get()->groupBy(fn ($entry) => (int) $entry->harvest_date->month);
        $details = [];
        foreach ($this->production->monthsForQuarter($record->quarter->quarter_number) as $month) {
            $active = $entries->get($month, collect());
            $detail = ['month' => $month, 'activity_count' => $active->count(),
                'total_quantity' => $this->production->sumQuantities($active->pluck('quantity'))];
            $row->monthlyDetails()->updateOrCreate(['month' => $month], $detail);
            $details[] = $detail;
        }
        $row->update(['actual_output' => $this->production->calculateActualOutput(
            $record->year, $record->quarter->quarter_number, $details)]);
    }

    private function write(MonitoringRecord $record, Request $request, callable $operation): mixed
    {
        $actor = $request->attributes->get('current_user');
        abort_unless($actor instanceof UserProfile && in_array($actor->role?->code, ['system_admin', 'field_officer'], true), 403);
        try {
            return DB::transaction(function () use ($record, $request, $actor, $operation) {
                // Shared order: actor/role -> association -> project -> record -> production -> entry.
                $locked = $this->monitoring->lockRecordForMutation($record, $actor);
                $locked->setRelation('quarter', $locked->quarter()->sharedLock()->firstOrFail());
                $this->production->assertRevision($locked, $request->all());
                $result = $operation($locked, $actor);
                $locked->update(['updated_by' => $actor->id]);
                return $result;
            }, 3);
        } catch (PDOException $exception) {
            $state = $exception->errorInfo[0] ?? null;
            Log::warning('Daily harvest write failed.', ['sqlstate' => $state]);
            if ($state === '23505') {
                throw ValidationException::withMessages(['harvest_date' => 'A conflicting product or harvest date was saved. Reload before retrying.'])->status(409);
            }
            if (in_array($state, ['23514', '22003'], true)) {
                throw ValidationException::withMessages(['quantity' => 'Production could not be saved within its period, quantity or history constraints.']);
            }
            throw ValidationException::withMessages(['production' => 'The harvest change could not be confirmed as saved. Reload and review before retrying.'])->status(503);
        }
    }
}
