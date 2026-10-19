<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SexCategory extends Model
{
    protected $table = 'sex_categories';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $guarded = [];
}