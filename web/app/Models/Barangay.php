<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    protected $table = 'barangays';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'municipality_id',
        'name',
        'psgc_code',
        'source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class, 'barangay_id');
    }
}