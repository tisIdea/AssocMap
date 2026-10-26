<?php

namespace App\Services\Monitoring;

use App\Exceptions\MonitoringMaterialsConflictException;
use App\Models\MonitoringRecord;
use LogicException;

class MaterialsMonitoringService
{
    public function revision(MonitoringRecord $record): string
    {
        if ($record->getAttribute('materials_version') === null) {
            throw new LogicException('The Monitoring Materials version migration is required.');
        }
        // Use the displayed snapshot; never attach a newer token to an older draft.
        $record->loadMissing('materials');
        $rows = $record->materials->sortBy('id')->map(fn ($material) => [
            $material->id, $material->project_material_id, $material->condition,
            $material->scheduled_maintenance?->format('Y-m-d'),
            $material->actual_maintenance?->format('Y-m-d'), $material->remarks,
        ])->values()->all();

        return hash('sha256', json_encode([$record->id, $record->project_id, $record->year,
            $record->quarter_id, (string) $record->materials_version, $rows], JSON_THROW_ON_ERROR));
    }

    public function assertRevision(MonitoringRecord $record, array $data): void
    {
        $record->setRelation('materials', $record->materials()->orderBy('id')->lockForUpdate()->get());
        $revision = $data['materials_revision'] ?? null;
        if (! is_string($revision) || ! hash_equals($this->revision($record), $revision)) {
            throw new MonitoringMaterialsConflictException;
        }
    }

    public function includesEdits(array $data): bool
    {
        return array_key_exists('materials', $data) || array_key_exists('deleted_material_ids', $data);
    }
}
