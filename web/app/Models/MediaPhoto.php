<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class MediaPhoto extends Model
{
    use HasUuids;

    protected $table = 'media_photos';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    // Generic serialization exposes only safe metadata, never recovery/storage internals.
    protected $visible = ['id', 'caption', 'display_order', 'image_format', 'display_width',
        'display_height', 'thumbnail_width', 'thumbnail_height', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['lease_until' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
            'display_order' => 'integer', 'display_width' => 'integer', 'display_height' => 'integer',
            'thumbnail_width' => 'integer', 'thumbnail_height' => 'integer',
            'display_bytes' => 'integer', 'thumbnail_bytes' => 'integer'];
    }
}
