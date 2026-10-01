<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipality extends Model
{
    protected $table = 'municipalities';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'psgc_code',
        'province',
        'region',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class, 'municipality_id');
    }
}