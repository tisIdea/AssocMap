<?php

namespace App\Services;

use App\Models\MonitoringRecord;
use App\Exceptions\MonitoringIncomeConflictException;
use LogicException;

class IncomeMonitoringService
{
    public function revision(MonitoringRecord $record): string
    {
        if ($record->getAttribute('income_version') === null) {
            throw new LogicException('The Monitoring Income version migration is required.');
        }
        // Hash the same loaded snapshot that is displayed, never a later independent query.
        $record->loadMissing('production.income');
        $rows = $record->production->sortBy('id')->map(fn ($production) => [
            $production->id, $production->project_product_id,
            $production->income->sortBy('id')->map(fn ($income) => [
                $income->id, $income->period_type, $income->month, $income->gross_income, $income->remarks,
            ])->values()->all(),
        ])->values()->all();

        return hash('sha256', json_encode([$record->id, $record->project_id, $record->year,
            $record->quarter_id, (string) $record->income_version, $rows], JSON_THROW_ON_ERROR));
    }

    public function assertRevision(MonitoringRecord $record, array $data): void
    {
        $revision = $data['income_revision'] ?? null;
        if (! is_string($revision) || ! hash_equals($this->revision($record), $revision)) {
            throw new MonitoringIncomeConflictException;
        }
    }

    public function includesEdits(array $productionRows): bool
    {
        foreach ($productionRows as $row) {
            if (is_array($row) && (array_key_exists('income', $row) || array_key_exists('deleted_income_ids', $row))) return true;
        }
        return false;
    }
}
