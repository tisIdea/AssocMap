<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringIncome extends Model
{
    use HasUuids;

    protected $table = 'monitoring_income';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'monitoring_production_id',
        'period_type',
        'month',
        'gross_income',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'gross_income' => 'decimal:2',
        ];
    }

    public function monitoringProduction(): BelongsTo
    {
        return $this->belongsTo(
            MonitoringProduction::class,
            'monitoring_production_id'
        );
    }
}