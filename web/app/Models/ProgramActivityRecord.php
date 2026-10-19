<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramActivityRecord extends Model
{
    use HasUuids;

    protected $table = 'program_activity_records';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'saad_activity_id',
        'association_id',
        'related_project_id',
        'title',
        'description',
        'program_year',
        'activity_date',
        'venue',
        'status',
        'conducted_by',
        'conducted_by_user_id',
        'remarks',
        'created_by',
        'updated_by',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'program_year' => 'integer',
        'activity_date' => 'date',
        'archived_at' => 'datetime',
    ];

    public function saadActivity(): BelongsTo
    {
        return $this->belongsTo(
            SaadActivity::class,
            'saad_activity_id'
        );
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(
            Association::class
        );
    }

    public function relatedProject(): BelongsTo
    {
        return $this->belongsTo(
            Project::class,
            'related_project_id'
        );
    }

    public function conductedByUser(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'conducted_by_user_id'
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