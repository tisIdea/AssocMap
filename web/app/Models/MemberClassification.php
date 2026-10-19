<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MemberClassification extends Model
{
    protected $table = 'member_classifications';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $guarded = [];

    public function memberApplications(): BelongsToMany
    {
        return $this->belongsToMany(
            MemberApplication::class,
            'member_application_classifications',
            'classification_id',
            'member_application_id'
        );
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            Member::class,
            'member_classification_assignments',
            'classification_id',
            'member_id'
        );
    }
}