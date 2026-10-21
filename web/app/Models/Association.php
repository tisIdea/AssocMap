<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Association extends Model
{
    use HasUuids;

    protected $table = 'associations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $hidden = ['specific_address'];

    protected $fillable = [
        'name',
        'acronym',
        'area_id',
        'saad_phase2_join_date',
        'primary_field_officer_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'saad_phase2_join_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id');
    }

    public function locationBarangay(): ?Barangay
    {
        return app(\App\Services\AssociationGeographyService::class)->usesDirectGeography()
            ? $this->barangay : $this->area?->barangay;
    }

    public function primaryFieldOfficer(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'primary_field_officer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'updated_by');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'archived_by');
    }

    public function associationAccount(): HasOne
    {
        return $this->hasOne(AssociationAccount::class, 'association_id');
    }

    public function currentPresident(): HasOne
    {
        return $this->hasOne(Member::class, 'association_id')
            ->where('member_status', 'active')->whereNull('archived_at')
            ->whereHas('position', fn ($query) => $query->where('code', 'PRESIDENT'))
            ->select(['id', 'association_id', 'first_name', 'middle_name', 'last_name', 'suffix']);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(AssociationRegistration::class, 'association_id');
    }
}
