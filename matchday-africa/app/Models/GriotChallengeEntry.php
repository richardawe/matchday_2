<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fan's scored round: points across the three matches and whether that beat the Griot. */
class GriotChallengeEntry extends Model
{
    protected $guarded = [];

    protected $casts = ['picks' => 'array', 'beat' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
