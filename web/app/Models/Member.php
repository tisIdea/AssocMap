<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Member extends Model
{
    use HasUuids;

    protected $table = 'members';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'association_id',
        'source_application_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birth_date',
        'sex_id',
        'contact_number',
        'fishr_registration_number',
        'position_id',
        'position_other_text',
        'date_registered',
        'member_status',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'date_registered' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function sex(): BelongsTo
    {
        return $this->belongsTo(
            SexCategory::class,
            'sex_id'
        );
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(
            AssociationPosition::class,
            'position_id'
        );
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'archived_by'
        );
    }

    public function classifications(): BelongsToMany
    {
        return $this->belongsToMany(
            MemberClassification::class,
            'member_classification_assignments',
            'member_id',
            'classification_id'
        );
    }
}