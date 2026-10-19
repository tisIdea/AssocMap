<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringMaterial extends Model
{
    use HasUuids;

    protected $table = 'monitoring_materials';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'monitoring_record_id',
        'project_material_id',
        'condition',
        'scheduled_maintenance',
        'actual_maintenance',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_maintenance' => 'date',
            'actual_maintenance' => 'date',
        ];
    }

    public function monitoringRecord(): BelongsTo
    {
        return $this->belongsTo(
            MonitoringRecord::class,
            'monitoring_record_id'
        );
    }

    public function projectMaterial(): BelongsTo
    {
        return $this->belongsTo(
            ProjectMaterial::class,
            'project_material_id'
        );
    }
}