<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GisLocation extends Model
{
    use HasUuids;

    public const TYPES = ['project_point', 'project_boundary'];

    protected $table = 'gis_locations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected $hidden = ['geom'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'published_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
