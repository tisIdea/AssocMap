<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingParticipant extends Model
{
    use HasUuids;

    protected $table = 'training_participants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'training_id',
        'member_id',
        'attendance_status',
        'remarks',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}