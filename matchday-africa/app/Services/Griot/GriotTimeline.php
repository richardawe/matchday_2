<?php

namespace App\Services\Griot;

use App\Models\FootballMatch;
use App\Models\GriotPost;
use App\Models\MatchPlay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps a match's standard plays and its Griot posts. Safe to run repeatedly: plays and posts are
 * keyed, a play the feed withdraws is removed, and a post whose moment disappears (a goal ruled
 * out after the feed reported it) is removed with it. Written captions and audio survive reruns.
 */
class GriotTimeline
{
    public function __construct(private ?MomentPicker $picker = null) {}

    /** Off until GRIOT_TIMELINE=true and the migration exists, so nothing changes before then. */
    public function ready(): bool
    {
        static $tables = null;

        return config('griot.enabled') && ($tables ??= Schema::hasTable('match_plays') && Schema::hasTable('griot_posts'));
    }

    /** Store a provider's plays for a match, dropping that provider's plays it no longer reports. */
    public function savePlays(FootballMatch $match, array $plays, string $provider = 'espn'): int
    {
        if (!$plays) {
            return 0;
        }
        $now = now();
        $rows = array_map(fn ($p) => [
            'match_id' => $match->id, 'provider' => $provider, 'provider_key' => (string) $p['provider_key'], 'seq' => (int) $p['seq'],
            'period' => (int) $p['period'], 'minute' => (int) $p['minute'], 'extra_minute' => $p['extra_minute'] ?? null,
            'type' => $p['type'], 'side' => $p['side'] ?? null, 'player' => $p['player'] ?? null, 'player2' => $p['player2'] ?? null,
            'x' => $p['x'] ?? null, 'y' => $p['y'] ?? null, 'detail' => json_encode($p['detail'] ?? []), 'text' => $p['text'] ?? null,
            'happened_at' => isset($p['happened_at']) ? \Illuminate\Support\Carbon::parse($p['happened_at'])->utc()->format('Y-m-d H:i:s') : null,
            'created_at' => $now, 'updated_at' => $now,
        ], $plays);
        foreach (array_chunk($rows, 200) as $chunk) {
            MatchPlay::upsert($chunk, ['match_id', 'provider', 'provider_key'],
                ['seq', 'period', 'minute', 'extra_minute', 'type', 'side', 'player', 'player2', 'x', 'y', 'detail', 'text', 'happened_at', 'updated_at']);
        }
        MatchPlay::where('match_id', $match->id)->where('provider', $provider)
            ->whereNotIn('provider_key', array_column($rows, 'provider_key'))->delete();

        return count($rows);
    }

    /** Rebuild the match's posts from its plays. Returns how many posts are new. */
    public function build(FootballMatch $match): int
    {
        $picker = $this->picker ??= MomentPicker::fromConfig();
        $plays = MatchPlay::where('match_id', $match->id)->orderBy('seq')->get()
            ->map(fn (MatchPlay $p) => $p->only(['provider', 'provider_key', 'seq', 'period', 'minute', 'extra_minute', 'type', 'side', 'player', 'player2', 'x', 'y', 'detail', 'text']))
            ->all();
        if (!$plays) {
            return 0;
        }
        $finished = $match->isFinished() && !$match->statusInferred();
        $posts = $picker->pick($plays, $finished);

        $created = 0;
        foreach ($posts as $post) {
            $row = GriotPost::firstOrNew(['key' => "{$match->id}:{$post['key']}"]);
            $created += $row->exists ? 0 : 1;
            $changed = $row->exists && ($row->facts != $post['facts'] || $row->home_score !== $post['home_score'] || $row->away_score !== $post['away_score']);
            $row->fill([
                'match_id' => $match->id, 'kind' => $post['kind'], 'side' => $post['side'], 'period' => $post['period'],
                'minute' => $post['minute'], 'extra_minute' => $post['extra_minute'], 'seq' => $post['seq'],
                'home_score' => $post['home_score'], 'away_score' => $post['away_score'], 'facts' => $post['facts'],
                'play_keys' => $post['play_keys'], 'confidence' => $post['confidence'], 'picker' => $picker->name(),
            ]);
            // New facts (a pressure spell grew) need new words and a new recording.
            if ($changed) {
                $row->written = null;
                $row->audio_path = null;
            }
            $row->save();
        }
        GriotPost::where('match_id', $match->id)
            ->whereNotIn('key', array_map(fn ($p) => "{$match->id}:{$p['key']}", $posts))
            ->delete();

        return $created;
    }

    /** Matches whose plays moved recently: live ones and those just finished. */
    public function syncRecent(): int
    {
        if (!$this->ready()) {
            return 0;
        }
        $ids = MatchPlay::where('updated_at', '>=', now()->subMinutes(15))->distinct()->pluck('match_id');

        return FootballMatch::whereIn('id', $ids)->get()->sum(fn ($match) => $this->build($match));
    }

    /** The match's posts, newest first, as the page shows them. */
    public function posts(FootballMatch $match): Collection
    {
        if (!$this->ready()) {
            return collect();
        }

        return GriotPost::where('match_id', $match->id)->orderByDesc('seq')->orderByDesc('id')->get();
    }

    /** Shots with pitch positions, for the shot map. */
    public function shots(FootballMatch $match): Collection
    {
        if (!$this->ready()) {
            return collect();
        }

        return MatchPlay::where('match_id', $match->id)
            ->whereIn('type', ['goal', 'own_goal', 'penalty_goal', 'penalty_miss', 'shot_saved', 'shot_missed', 'shot_blocked', 'woodwork'])
            ->orderBy('seq')->get();
    }

    /**
     * Who's on top, minute by minute: each shot weighs more the nearer goal it came and the better
     * its outcome, smoothed over five minutes. Positive = home. Returns [minute => value in -1..1].
     */
    public function momentum(Collection $shots, int $minutes = 96): array
    {
        $raw = array_fill(0, $minutes, 0.0);
        foreach ($shots as $shot) {
            // First-half added time sits at 45 so it never overlaps the second half's minutes.
            $time = (int) $shot->minute + (int) ($shot->extra_minute ?? 0);
            $minute = min($minutes - 1, $shot->period === 1 ? min(45, $time) : $time);
            $weight = match ($shot->type) {
                'goal', 'own_goal', 'penalty_goal' => 2.2,
                'shot_saved', 'woodwork', 'penalty_miss' => 1.4,
                default => 1.0,
            } * (($shot->x ?? 85) / 90);
            $raw[$minute] += $shot->side === 'away' ? -$weight : $weight;
        }
        $smooth = [];
        foreach ($raw as $i => $_) {
            $sum = 0.0;
            for ($k = -2; $k <= 2; $k++) {
                $sum += ($raw[$i + $k] ?? 0) * (1 - abs($k) * 0.25);
            }
            $smooth[$i] = $sum;
        }
        $max = max(1.0, ...array_map('abs', $smooth));

        return array_map(fn ($v) => round($v / $max, 3), $smooth);
    }
}
