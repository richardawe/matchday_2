<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of the round's three matches with the Griot's call and the facts behind its line. */
class GriotChallengeMatch extends Model
{
    protected $guarded = [];

    protected $casts = ['facts' => 'array'];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(GriotChallenge::class, 'griot_challenge_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function call(): string
    {
        return $this->home_pick.'–'.$this->away_pick;
    }
}
