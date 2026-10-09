<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Training extends Model
{
    use HasUuids;

    protected $table = 'trainings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'association_id',
        'related_project_id',
        'saad_activity_id',
        'title',
        'description',
        'program_year',
        'start_date',
        'end_date',
        'venue',
        'conducted_by',
        'training_cost',
        'status',
        'remarks',
        'created_by',
        'updated_by',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'program_year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'training_cost' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function relatedProject(): BelongsTo
    {
        return $this->belongsTo(
            Project::class,
            'related_project_id'
        );
    }

    public function saadActivity(): BelongsTo
    {
        return $this->belongsTo(
            SaadActivity::class,
            'saad_activity_id'
        );
    }

    public function participants(): HasMany
    {
        return $this->hasMany(
            TrainingParticipant::class
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
}