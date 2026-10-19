<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringVisitReport extends Model
{
    use HasUuids;

    protected $table = 'monitoring_visit_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'conducted_by_user_id',
        'conducted_by',
        'monitoring_date',
        'monitored_by',
        'site_validated',
        'issues_encountered',
        'actions_taken',
        'overall_remarks',
        'created_by',
        'updated_by',
        'is_legacy_import',
    ];

    protected function casts(): array
    {
        return [
            'monitoring_date' => 'date:Y-m-d',
            'site_validated' => 'boolean',
            'is_legacy_import' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function monitoringRecord(): BelongsTo
    {
        return $this->belongsTo(MonitoringRecord::class, 'monitoring_record_id');
    }

    public function conductedByUser(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'conducted_by_user_id');
    }

    public function monitoredBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'monitored_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'updated_by');
    }
}
