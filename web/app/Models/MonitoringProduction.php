<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringProduction extends Model
{
    use HasUuids;

    protected $table = 'monitoring_production';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'monitoring_record_id',
        'project_product_id',
        'target_output',
        'actual_output',
        'unit',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'target_output' => 'decimal:2',
            'actual_output' => 'decimal:2',
        ];
    }

    public function monitoringRecord(): BelongsTo
    {
        return $this->belongsTo(
            MonitoringRecord::class,
            'monitoring_record_id'
        );
    }

    public function projectProduct(): BelongsTo
    {
        return $this->belongsTo(
            ProjectProduct::class,
            'project_product_id'
        );
    }

    public function income(): HasMany
    {
        return $this->hasMany(
            MonitoringIncome::class,
            'monitoring_production_id'
        );
    }
}