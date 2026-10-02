<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssociationPosition extends Model
{
    protected $table = 'association_positions';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $guarded = [];
}