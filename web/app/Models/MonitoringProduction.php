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
        'quantity_source',
    ];

    protected function casts(): array
    {
        return [
            'target_output' => 'decimal:2',
            'actual_output' => 'decimal:2',
            'harvest_version' => 'integer',
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

    public function monthlySubtotal(): string
    {
        $cents = $this->monthlyDetails->sum(fn ($detail) => (int) str_replace('.', '', $detail->total_quantity));
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public function monthlyDetails(): HasMany
    {
        return $this->hasMany(MonitoringProductionMonthly::class, 'monitoring_production_id')->orderBy('month');
    }

    public function harvestEntries(): HasMany
    {
        return $this->hasMany(MonitoringHarvestEntry::class, 'monitoring_production_id')
            ->orderBy('harvest_date')->orderBy('id');
    }

    public function income(): HasMany
    {
        return $this->hasMany(
            MonitoringIncome::class,
            'monitoring_production_id'
        );
    }
}
