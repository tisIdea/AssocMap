<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssociationAccount extends Model
{
    protected $table = 'association_accounts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'association_id',
        'user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'linked_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class, 'association_id');
    }

    public function userProfile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'linked_by');
    }
}
