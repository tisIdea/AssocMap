<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMaterial extends Model
{
    use HasUuids;

    protected $table = 'project_materials';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'project_id',
        'item_name',
        'quantity_planned',
        'quantity_delivered',
        'unit',
        'unit_cost',
        'delivery_status',
        'delivery_date',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quantity_planned' => 'decimal:2',
            'quantity_delivered' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'delivery_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(
            Project::class,
            'project_id'
        );
    }
}