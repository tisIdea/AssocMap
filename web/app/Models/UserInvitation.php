<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserInvitation extends Model
{
    use HasUuids;

    public const UNRESOLVED_STATUSES = ['pending', 'processing', 'sent', 'failed'];

    public const IDENTITY_CONFLICT_PREFIX = 'profile_identity_conflict:';

    public const PROVIDER_IDENTITY_CODES = [
        'provider_request_started', 'provider_outcome_unknown', 'provider_response_invalid',
        'provider_request_rejected', 'profile_sync_failed', 'profile_reconciliation_required', 'provider_state_changed',
    ];

    protected $table = 'user_invitations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['email', 'display_name', 'role_id', 'association_id'];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'invited_by');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'auth_user_id');
    }

    public function reconciliationAuthUserId(): ?string
    {
        if (! str_starts_with($this->last_error_code ?? '', self::IDENTITY_CONFLICT_PREFIX)) return $this->auth_user_id;
        $id = substr($this->last_error_code, strlen(self::IDENTITY_CONFLICT_PREFIX));

        return Str::isUuid($id) ? $id : null;
    }
}
