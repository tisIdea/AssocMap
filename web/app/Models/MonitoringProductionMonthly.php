<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringProductionMonthly extends Model
{
    use HasUuids;

    protected $table = 'monitoring_production_monthly';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['month', 'activity_count', 'total_quantity', 'remarks'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'activity_count' => 'integer', 'total_quantity' => 'decimal:2'];
    }

    public function monitoringProduction(): BelongsTo
    {
        return $this->belongsTo(MonitoringProduction::class, 'monitoring_production_id');
    }
}
