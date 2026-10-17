<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoringRecord extends Model
{
    use HasUuids;

    protected $table = 'monitoring_records';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'project_id',
        'year',
        'quarter_id',
        'monitoring_date',
        'monitored_by',
        'site_validated',
        'project_status',
        'issues_encountered',
        'actions_taken',
        'overall_remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter_id' => 'integer',
            'income_version' => 'integer',
            'monitoring_date' => 'date',
            'site_validated' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(
            Project::class,
            'project_id'
        );
    }

    public function quarter(): BelongsTo
    {
        return $this->belongsTo(
            Quarter::class,
            'quarter_id'
        );
    }

    public function monitoredBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'monitored_by'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'created_by'
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'updated_by'
        );
    }

    public function visitReports(): HasMany
    {
        return $this->hasMany(MonitoringVisitReport::class, 'monitoring_record_id')
            ->orderByDesc('monitoring_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function production(): HasMany
    {
        return $this->hasMany(
            MonitoringProduction::class,
            'monitoring_record_id'
        );
    }

    public function materials(): HasMany
    {
        return $this->hasMany(
            MonitoringMaterial::class,
            'monitoring_record_id'
        );
    }
}
