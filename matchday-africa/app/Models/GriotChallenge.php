<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One round of Beat the Griot: three matches, the Griot's calls, and (once scored) its winners. */
class GriotChallenge extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_kickoff' => 'datetime',
        'last_kickoff' => 'datetime',
        'scored_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function picks(): HasMany
    {
        return $this->hasMany(GriotChallengeMatch::class)->orderBy('position');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GriotChallengeEntry::class);
    }

    public function winners(): HasMany
    {
        return $this->hasMany(GriotChallengeWinner::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** The first kick-off as a real instant (first_kickoff holds UTC wall-clock values). */
    public function firstKickoffUtc(): Carbon
    {
        return Carbon::parse($this->first_kickoff->format('Y-m-d H:i:s'), 'UTC');
    }

    public function lastKickoffUtc(): Carbon
    {
        return Carbon::parse($this->last_kickoff->format('Y-m-d H:i:s'), 'UTC');
    }

    /** "Sat 8 – Sun 9 Nov" in Lagos time. */
    public function datesLabel(): string
    {
        $tz = 'Africa/Lagos';
        $a = $this->firstKickoffUtc()->setTimezone($tz);
        $b = $this->lastKickoffUtc()->setTimezone($tz);
        if ($a->isSameDay($b)) {
            return $a->format('D j M');
        }

        return $a->format($a->month === $b->month ? 'D j' : 'D j M').' – '.$b->format('D j M');
    }
}
