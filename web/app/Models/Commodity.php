<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commodity extends Model
{
    protected $table = 'commodities';

    public $timestamps = true;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'source',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            FisheriesProjectCategory::class,
            'category_id'
        );
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
            'commodity_id'
        );
    }
}