<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationAgency extends Model
{
    protected $table = 'registration_agencies';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
