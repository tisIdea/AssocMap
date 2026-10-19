<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MemberApplication extends Model
{
    use HasUuids;

    protected $table = 'member_applications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'association_id',
        'submitted_by_user_id',
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
        'endorsement_letter',
        'applicant_photo_path',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected $hidden = ['endorsement_letter', 'applicant_photo_path'];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'submitted_by_user_id'
        );
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(
            UserProfile::class,
            'reviewed_by'
        );
    }

    public function member(): HasOne
    {
        return $this->hasOne(
            Member::class,
            'source_application_id'
        );
    }

    public function classifications(): BelongsToMany
    {
        return $this->belongsToMany(
            MemberClassification::class,
            'member_application_classifications',
            'member_application_id',
            'classification_id'
        );
    }
}
