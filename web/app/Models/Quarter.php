<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quarter extends Model
{
    protected $table = 'quarters';

    protected $keyType = 'int';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'quarter_number',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'quarter_number' => 'integer',
        ];
    }

    public function monitoringRecords(): HasMany
    {
        return $this->hasMany(
            MonitoringRecord::class,
            'quarter_id'
        );
    }
}