<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $table = 'projects';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'association_id',
        'saad_activity_id',
        'fisheries_category_id',
        'commodity_id',
        'title',
        'description',
        'program_year',
        'implementation_start_date',
        'implementation_end_date',
        'status',
        'remarks',
        'created_by',
        'updated_by',
        'archived_at',
        'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'program_year' => 'integer',
            'implementation_start_date' => 'date',
            'implementation_end_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(
            Association::class
        );
    }

    public function saadActivity(): BelongsTo
    {
        return $this->belongsTo(
            SaadActivity::class,
            'saad_activity_id'
        );
    }

    public function fisheriesCategory(): BelongsTo
    {
        return $this->belongsTo(
            FisheriesProjectCategory::class,
            'fisheries_category_id'
        );
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(
            Commodity::class,
            'commodity_id'
        );
    }

    public function materials(): HasMany
    {
        return $this->hasMany(
            ProjectMaterial::class,
            'project_id'
        );
    }

    public function products(): HasMany
    {
        return $this->hasMany(
            ProjectProduct::class,
            'project_id'
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

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'archived_by'
        );
    }

        public function monitoringRecords(): HasMany
    {
        return $this->hasMany(
            MonitoringRecord::class,
            'project_id'
        );
    }
}