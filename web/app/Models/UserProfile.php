<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserProfile extends Model
{
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
        ];
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
    return $this->hasOne(AssociationAccount::class, 'user_id');
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