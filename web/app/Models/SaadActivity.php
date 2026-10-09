<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaadActivity extends Model
{
    protected $table = 'saad_activities';

    public $timestamps = true;

    protected $fillable = [
        'subcomponent_id',
        'code',
        'name',
        'description',
        'source',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'created_by'
        );
    }

    public function projects(): HasMany
    {
        return $this->hasMany(
            Project::class,
            'saad_activity_id'
        );
    }
}