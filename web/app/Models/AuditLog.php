<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'performed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit events cannot be updated.');
        });
        static::deleting(function () {
            throw new LogicException('Audit events cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'actor_user_id');
    }
}
