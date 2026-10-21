<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringHarvestEntry extends Model
{
    use HasUuids;

    protected $table = 'monitoring_harvest_entries';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['harvest_date', 'quantity', 'remarks', 'created_by', 'updated_by',
        'voided_at', 'voided_by', 'void_reason'];

    protected function casts(): array
    {
        return ['harvest_date' => 'immutable_date', 'quantity' => 'decimal:2', 'voided_at' => 'immutable_datetime'];
    }

    public function monitoringProduction(): BelongsTo
    {
        return $this->belongsTo(MonitoringProduction::class, 'monitoring_production_id');
    }
}
