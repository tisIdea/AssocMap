<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FisheriesProjectCategory extends Model
{
    protected $table = 'fisheries_project_categories';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function commodities(): HasMany
    {
        return $this->hasMany(
            Commodity::class,
            'category_id'
        );
    }

    public function projects(): HasMany
    {
        return $this->hasMany(
            Project::class,
            'fisheries_category_id'
        );
    }
}