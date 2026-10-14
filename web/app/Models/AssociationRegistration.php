<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssociationRegistration extends Model
{
    use HasUuids;

    protected $table = 'association_registrations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['registration_date' => 'date', 'expiration_date' => 'date', 'is_primary' => 'boolean'];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(RegistrationAgency::class, 'agency_id');
    }
}
