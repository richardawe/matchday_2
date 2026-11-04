<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of the round's drawn winners; an admin credits the data by hand and records it here. */
class GriotChallengeWinner extends Model
{
    protected $guarded = [];

    protected $casts = ['credited_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(GriotChallenge::class, 'griot_challenge_id');
    }

    public function creditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credited_by');
    }
}
