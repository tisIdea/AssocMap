<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;

class UserProfile extends Model
{
    private ?bool $invitationAccessAllowed = null;

    private ?string $verifiedAuthenticationEmail = null;

    protected $table = 'user_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'role_id',
        'display_name',
        'contact_number',
        'is_active',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'auth_sessions_valid_after' => 'immutable_datetime',
        ];
    }

    public static function findWithRole(string $id, bool $useWritePdo = false): ?self
    {
        $roleColumns = ['id', 'code', 'name', 'description', 'is_active', 'created_at', 'updated_at'];
        $query = static::query()->select('user_profiles.*')
            ->leftJoin('roles as profile_role', 'profile_role.id', '=', 'user_profiles.role_id');
        foreach ($roleColumns as $column) $query->addSelect('profile_role.'.$column.' as auth_role_'.$column);
        if ($useWritePdo) $query->useWritePdo();
        $row = $query->whereKey($id)->toBase()->first();
        if ($row === null) return null;

        $attributes = (array) $row;
        $roleAttributes = [];
        foreach ($roleColumns as $column) {
            $roleAttributes[$column] = $attributes['auth_role_'.$column];
            unset($attributes['auth_role_'.$column]);
        }
        // Hydrate separate models with their normal casts and original attributes.
        $profile = $query->getModel()->newFromBuilder($attributes, $query->getConnection()->getName());
        $role = $roleAttributes['id'] === null ? null
            : (new Role)->newFromBuilder($roleAttributes, $profile->getConnectionName());

        return $profile->setRelation('role', $role);
    }

    public function canAccessApplication(?array $verifiedIdentity = null, bool $throwOnDatabaseError = false): bool
    {
        if (! $this->is_active || $this->role?->is_active !== true) return false;
        // A pending provider change is an access hold, not a revocation or unlink.
        if ($this->getAttribute('email_change_hold_id') !== null) return false;
        if ($verifiedIdentity !== null) {
            if (! is_string($verifiedIdentity['id'] ?? null)
                || strtolower($verifiedIdentity['id']) !== strtolower($this->id)) return false;
            $email = is_string($verifiedIdentity['email'] ?? null) ? mb_strtolower(trim($verifiedIdentity['email'])) : null;
            if ($this->getAttribute('approved_email') !== null && $email !== $this->approved_email) return false;
            $this->invitationAccessAllowed = null;
            $this->verifiedAuthenticationEmail = $email;
        }
        if ($this->invitationAccessAllowed !== null) return $this->invitationAccessAllowed;
        try {
            $invitations = UserInvitation::query()->when($throwOnDatabaseError, fn ($query) => $query->useWritePdo())
                ->where(function ($query) {
                    $query->where('auth_user_id', $this->id);
                    if ($this->verifiedAuthenticationEmail !== null) {
                        $query->orWhere(fn ($unbound) => $unbound->where('email', $this->verifiedAuthenticationEmail)
                            ->where(fn ($phase) => $phase->whereIn('last_error_code', UserInvitation::PROVIDER_IDENTITY_CODES)
                                ->orWhere('last_error_code', 'like', UserInvitation::IDENTITY_CONFLICT_PREFIX.'%')));
                    }
                })->get(['auth_user_id', 'status', 'last_error_code', 'accepted_at', 'created_at', 'last_attempt_at']);
        } catch (QueryException $exception) {
            if ($throwOnDatabaseError) throw $exception;
            return $this->invitationAccessAllowed = false;
        }
        $accepted = $invitations->filter(fn ($row) => $row->auth_user_id === $this->id
            && $row->status === 'accepted' && $row->accepted_at !== null)
            ->sortByDesc(fn ($row) => $row->accepted_at->getTimestamp())->first();
        foreach ($invitations as $invitation) {
            if ($invitation->status === 'accepted' && $invitation->auth_user_id === $this->id) continue;
            if ($invitation->auth_user_id !== $this->id && $invitation->last_attempt_at !== null
                && $this->created_at?->lt($invitation->last_attempt_at)) continue;
            if ($accepted && ($invitation->created_at->lte($accepted->accepted_at)
                || in_array($invitation->status, ['cancelled', 'expired'], true))) continue;
            $conflictingId = $invitation->reconciliationAuthUserId();
            if ($invitation->auth_user_id !== $this->id && $conflictingId !== null && $conflictingId !== $this->id) continue;

            return $this->invitationAccessAllowed = false;
        }

        return $this->invitationAccessAllowed = true;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function assignedAssociations(): HasMany
{
    return $this->hasMany(Association::class, 'primary_field_officer_id');
}

public function associationAccount(): HasOne
{
    return $this->hasOne(AssociationAccount::class, 'user_id')->where('is_active', true);
}

public function associationAccountHistory(): HasMany
{
    return $this->hasMany(AssociationAccount::class, 'user_id');
}

public function createdAssociations(): HasMany
{
    return $this->hasMany(Association::class, 'created_by');
}

public function updatedAssociations(): HasMany
{
    return $this->hasMany(Association::class, 'updated_by');
}

public function archivedAssociations(): HasMany
{
    return $this->hasMany(Association::class, 'archived_by');
}

public function linkedAssociationAccounts(): HasMany
{
    return $this->hasMany(AssociationAccount::class, 'linked_by');
}
}
