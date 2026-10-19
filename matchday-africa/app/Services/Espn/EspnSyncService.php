<?php

namespace App\Services\Espn;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Keeps our matches in step with ESPN's scoreboard. Leagues that football-data.org already
 * covers are only linked and updated; ESPN-only leagues (CAF, African domestic) are created.
 */
class EspnSyncService
{
    private const CLOSED = ['FINISHED', 'POSTPONED', 'CANCELLED'];
    private const EVENT_TYPES_ON_SCOREBOARD = ['goal', 'yellow_card', 'red_card'];

    private array $result = ['success' => 0, 'errors' => 0, 'requests' => 0, 'created' => 0, 'linked' => 0, 'differences' => 0, 'unmatched' => []];

    public function __construct(private EspnClient $client, private EspnMatchMapper $mapper) {}

    /** Fixtures and results for every configured league across the configured date window. */
    public function syncFixtures(): array
    {
        $dates = collect(range(-config('espn.fixtures_days_back', 2), config('espn.fixtures_days_ahead', 10)))
            ->map(fn ($offset) => now('America/New_York')->addDays($offset)->toDateString());

        foreach (array_keys(config('espn.leagues', [])) as $slug) {
            foreach ($dates as $date) {
                $this->syncScoreboard($slug, $date);
                usleep(config('espn.request_pause_ms', 250) * 1000); // stay a light client across ~350 requests
            }
        }

        return $this->finish('fixtures');
    }

    /** Scoreboards for leagues that have a match inside the live window right now. */
    public function syncLive(): array
    {
        foreach (config('espn.leagues', []) as $slug => $config) {
            if (($config['fd_code'] ?? null) && !League::where('espn_slug', $slug)->exists()) {
                $this->footballDataLeague($config)?->update(['espn_slug' => $slug]);
            }
        }
        foreach ($this->activeLeagueDates() as $slug => $dates) {
            foreach ($dates as $date) {
                $this->syncScoreboard($slug, $date);
            }
        }

        return $this->finish('live');
    }

    /** Full summaries (stats, subs, line-ups) for live and just-finished matches. */
    public function syncDetails(): array
    {
        if (config('espn.shadow')) {
            return $this->finish('details');
        }
        $slugs = collect(config('espn.leagues', []))->filter(fn ($league) => $league['details'] ?? false)->keys();
        $matches = FootballMatch::with(['league', 'homeTeam', 'awayTeam'])
            ->whereNotNull('espn_id')
            ->whereHas('league', fn ($q) => $q->whereIn('espn_slug', $slugs))
            ->where(fn ($q) => $q->whereIn('status', FootballMatch::LIVE_STATUSES)
                ->orWhere(fn ($q) => $q->where('status', 'FINISHED')->where('match_date', '>=', now('UTC')->subHours(4))))
            ->get();

        foreach ($matches as $match) {
            $throttle = 'espn:summary:'.$match->espn_id;
            // Finished matches need one last pass; live ones refresh every couple of minutes.
            if (Cache::has($throttle) || ($match->status === 'FINISHED' && data_get($match->metadata, 'espn_summary_final'))) {
                continue;
            }
            Cache::put($throttle, true, now()->addSeconds(110));

            $summary = $this->request(fn () => $this->client->summary($match->league->espn_slug, $match->espn_id));
            if (!$summary) {
                continue;
            }
            $homeTeamId = (string) $match->homeTeam?->espn_id;
            $updates = $this->mapper->statistics($summary, $homeTeamId);
            $meta = $this->metadata($match);
            if ($lineups = $this->mapper->lineups($summary)) {
                $meta['espn_lineups'] = $lineups;
            }
            $meta['espn_summary_final'] = $match->status === 'FINISHED';
            $match->update($updates + ['metadata' => $meta]);
            $this->saveEvents($match, $this->mapper->summaryEvents($summary, $homeTeamId), 'summary');
            $this->result['success']++;
        }

        return $this->finish('details');
    }

    private function syncScoreboard(string $slug, string $date): void
    {
        $config = config('espn.leagues', [])[$slug] ?? null;
        $data = $this->request(fn () => $this->client->scoreboard($slug, $date));
        if (!$config || !$data) {
            return;
        }
        $league = $this->league($slug, $config);
        if (!$league) {
            return;
        }

        foreach (data_get($data, 'events', []) as $event) {
            $mapped = $this->mapper->match($event);
            if (!$mapped) {
                continue;
            }
            try {
                $this->apply($league, $config, $mapped);
            } catch (\Throwable $e) {
                $this->result['errors']++;
                report($e);
            }
        }
    }

    private function apply(League $league, array $config, array $data): void
    {
        $match = FootballMatch::where('espn_id', $data['espn_id'])->first() ?? $this->link($league, $data);

        if (!$match && ($config['fd_code'] ?? null)) {
            // football-data.org owns this league's fixture list; never create duplicates.
            $this->result['unmatched'][] = "{$league->name}: {$data['home']['name']} v {$data['away']['name']} ({$data['kickoff']->toDateTimeString()} UTC)";

            return;
        }

        if (config('espn.shadow')) {
            $this->shadow($match, $data);

            return;
        }

        $match ??= $this->create($league, $data);
        $meta = $this->metadata($match);
        $meta['espn_clock'] = $data['minute'] !== null ? $data['minute']."'".($data['extra_minute'] ? '+'.$data['extra_minute'] : '') : null;
        $meta['espn_status'] = $data['raw_status'];

        $updates = ['espn_id' => $data['espn_id'], 'espn_synced_at' => now(), 'last_api_update' => now(), 'data_source' => 'espn', 'metadata' => $meta];
        if ($data['status'] !== null) {
            $updates += ['status' => $data['status'], 'minute' => $data['minute'], 'home_score' => $data['home_score'], 'away_score' => $data['away_score']];
        }
        foreach (['venue_name', 'venue_city', 'attendance'] as $column) {
            if ($data[$column] && !$match->{$column}) {
                $updates[$column] = $data[$column];
            }
        }
        if (!$match->football_data_id) {
            $updates['match_date'] = $data['kickoff'];
        }
        $match->update($updates);

        if (($config['details'] ?? false) && in_array($data['status'], ['IN_PLAY', 'PAUSED', 'PENALTY_SHOOTOUT', 'FINISHED'], true)) {
            $this->saveEvents($match, $data['details'], 'scoreboard');
        }
        $this->result['success']++;
    }

    /**
     * Shadow mode: record what ESPN reports next to our own data and log disagreements, without
     * changing anything fans see. ESPN-only fixtures are counted but not created.
     */
    private function shadow(?FootballMatch $match, array $data): void
    {
        if (!$match) {
            $this->result['created']++;

            return;
        }
        $ours = [$match->status, $match->home_score, $match->away_score];
        $theirs = [$data['status'], $data['home_score'], $data['away_score']];
        $meta = $this->metadata($match);
        $meta['espn_shadow'] = ['status' => $data['status'], 'minute' => $data['minute'], 'home_score' => $data['home_score'], 'away_score' => $data['away_score'], 'at' => now()->toIso8601String()];
        $match->update(['espn_id' => $data['espn_id'], 'metadata' => $meta]);
        if ($data['status'] !== null && $ours !== $theirs) {
            $this->result['differences']++;
            Log::info('ESPN shadow difference', ['match_id' => $match->id, 'ours' => $ours, 'espn' => $theirs]);
        }
        $this->result['success']++;
    }

    /** Find the football-data.org match this ESPN event describes, by team IDs or names and kick-off. */
    private function link(League $league, array $data): ?FootballMatch
    {
        $candidates = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->where('league_id', $league->id)
            ->whereNull('espn_id')
            ->whereBetween('match_date', [$data['kickoff']->copy()->subHours(3), $data['kickoff']->copy()->addHours(3)])
            ->get();

        $bothTeams = $candidates->filter(fn ($m) => $this->sameTeam($m->homeTeam, $data['home']) && $this->sameTeam($m->awayTeam, $data['away']));
        $match = $bothTeams->count() === 1 ? $bothTeams->first() : null;

        // A single fixture at the same kick-off sharing one team is the same game under a different name.
        if (!$match) {
            $oneTeam = $candidates->filter(fn ($m) => abs($this->kickoff($m)->diffInMinutes($data['kickoff'])) <= 5
                && ($this->sameTeam($m->homeTeam, $data['home']) || $this->sameTeam($m->awayTeam, $data['away'])));
            $match = $oneTeam->count() === 1 ? $oneTeam->first() : null;
        }
        if (!$match) {
            return null;
        }

        $this->claimTeam($match->homeTeam, $data['home']['espn_id']);
        $this->claimTeam($match->awayTeam, $data['away']['espn_id']);
        $match->update(['espn_id' => $data['espn_id']]);
        $this->result['linked']++;

        return $match;
    }

    private function create(League $league, array $data): FootballMatch
    {
        $home = $this->team($league, $data['home']);
        $away = $this->team($league, $data['away']);
        $this->result['created']++;

        return FootballMatch::create([
            'espn_id' => $data['espn_id'],
            'league_id' => $league->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'match_date' => $data['kickoff'],
            'status' => $data['status'] ?? 'SCHEDULED',
            'data_source' => 'espn',
        ]);
    }

    /**
     * Upsert ESPN's incidents and drop the ones it no longer reports, plus any other provider's
     * copies, so a match never shows the same goal twice. A scoreboard pass only knows goals and
     * cards, so it leaves summary-only events (substitutions) alone.
     */
    private function saveEvents(FootballMatch $match, array $events, string $scope): void
    {
        if (empty($events)) {
            return;
        }
        $match->loadMissing(['homeTeam', 'awayTeam']);
        $kept = [];
        foreach ($events as $event) {
            $team = $event['side'] === 'home' ? $match->homeTeam : $match->awayTeam;
            $row = MatchEvent::updateOrCreate(
                ['football_data_id' => $this->stableId("{$match->espn_id}|{$event['type']}|{$event['clock']}|{$event['player_name']}")],
                [
                    'match_id' => $match->id, 'match_football_data_id' => $match->football_data_id,
                    'team_id' => $team->id, 'team_football_data_id' => $team->football_data_id,
                    'type' => $event['type'], 'sub_type' => Str::limit((string) $event['sub_type'], 50, ''),
                    'minute' => $event['minute'], 'extra_minute' => $event['extra_minute'],
                    'player_name' => $event['player_name'], 'assist_player_name' => $event['assist_player_name'],
                    'related_player_name' => $event['related_player_name'], 'description' => $event['description'],
                    'is_own_goal' => $event['is_own_goal'], 'is_penalty' => $event['is_penalty'], 'sort_order' => $event['sort_order'],
                    'metadata' => ['provider' => 'espn', 'scope' => $scope],
                ]
            );
            $kept[] = $row->id;
        }

        MatchEvent::where('match_id', $match->id)->whereNotIn('id', $kept)->get()
            ->filter(fn ($row) => data_get($row->metadata, 'provider') !== 'espn'
                || $scope === 'summary'
                || in_array($row->type, self::EVENT_TYPES_ON_SCOREBOARD, true))
            ->each->delete();

        if ($match->events_source !== 'espn') {
            $match->update(['events_source' => 'espn']);
        }
    }

    /** league slug => ESPN date buckets (US Eastern) of matches inside the live window. */
    private function activeLeagueDates(): Collection
    {
        $window = config('espn.live_window');

        return FootballMatch::with('league')
            ->whereHas('league', fn ($q) => $q->whereIn('espn_slug', array_keys(config('espn.leagues', []))))
            ->whereNotIn('status', ['POSTPONED', 'CANCELLED'])
            ->whereBetween('match_date', [now('UTC')->subMinutes($window['after_minutes']), now('UTC')->addMinutes($window['before_minutes'])])
            ->where(fn ($q) => $q->where('status', '!=', 'FINISHED')->orWhere('match_date', '>=', now('UTC')->subMinutes(150)))
            ->get()
            ->groupBy(fn ($m) => $m->league->espn_slug)
            ->map(fn ($matches) => $matches->map(fn ($m) => $this->kickoff($m)->setTimezone('America/New_York')->toDateString())->unique()->values());
    }

    /** Our league for an ESPN slug; null for a football-data.org league we haven't synced yet. */
    private function league(string $slug, array $config): ?League
    {
        $league = League::where('espn_slug', $slug)->first();
        if ($league) {
            return $league;
        }
        if ($config['fd_code'] ?? null) {
            $league = $this->footballDataLeague($config);
            $league?->update(['espn_slug' => $slug]);

            return $league;
        }

        return League::create([
            'espn_slug' => $slug,
            'name' => $config['name'],
            'country_name' => $config['country'] ?? null,
            'country_code' => $config['country_code'] ?? null,
            'type' => 'league',
            'is_active' => true,
        ]);
    }

    private function footballDataLeague(array $config): ?League
    {
        return League::whereNull('espn_slug')
            ->where(fn ($q) => $q->where('short_code', $config['fd_code'])->orWhere('football_data_id', $config['fd_id'] ?? 0))
            ->first();
    }

    private function team(League $league, array $data): Team
    {
        return Team::where('espn_id', $data['espn_id'])->first() ?? Team::create([
            'espn_id' => $data['espn_id'],
            'name' => $data['name'],
            'short_code' => $data['short_code'] ? Str::limit($data['short_code'], 10, '') : null,
            'country_name' => $league->country_name,
            'league_id' => $league->id,
            'is_active' => true,
        ]);
    }

    private function claimTeam(?Team $team, int $espnId): void
    {
        if ($team && !$team->espn_id && !Team::where('espn_id', $espnId)->exists()) {
            $team->update(['espn_id' => $espnId]);
        }
    }

    private function sameTeam(?Team $team, array $espn): bool
    {
        if (!$team) {
            return false;
        }
        if ($team->espn_id) {
            return (int) $team->espn_id === $espn['espn_id'];
        }
        $theirs = $this->tokens(config('espn.team_aliases', [])[$espn['name']] ?? $espn['name']);
        foreach (array_filter([$team->name, $team->common_name]) as $name) {
            $ours = $this->tokens($name);
            if ($ours && $theirs && (!array_diff($theirs, $ours) || !array_diff($ours, $theirs))) {
                return true;
            }
        }

        return false;
    }

    /** "Club Atlético de Madrid" → ['atletico', 'madrid']. */
    private function tokens(string $name): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff($words, ['fc', 'afc', 'cf', 'sc', 'ac', 'club', 'de', 'the', 'calcio', 'football', 'and']));
    }

    /**
     * match_date holds UTC wall-clock time (as football-data.org's sync writes it) but Eloquent
     * reads it in the app timezone (Africa/Lagos), so reinterpret it as UTC before comparing.
     */
    private function kickoff(FootballMatch $match): Carbon
    {
        return Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
    }

    private function request(callable $call): ?array
    {
        $this->result['requests']++;
        $data = $call();
        if ($data === null && !$this->client->isPaused()) {
            $this->result['errors']++;
        }

        return $data;
    }

    private function metadata(FootballMatch $match): array
    {
        $meta = $match->metadata;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) ? $meta : [];
    }

    private function stableId(string $key): int
    {
        return (int) hexdec(substr(hash('sha256', 'espn|'.$key), 0, 15));
    }

    private function finish(string $mode): array
    {
        $result = $this->result;
        $result['unmatched'] = array_values(array_unique($result['unmatched']));
        $result['paused_until'] = $this->client->pausedUntil();
        $result['message'] = config('espn.shadow')
            ? "[shadow] ESPN {$mode}: {$result['success']} matches compared, {$result['differences']} differ from ours, {$result['linked']} linked, {$result['created']} ESPN-only fixtures not created, "
                .count($result['unmatched'])." unmatched, {$result['requests']} requests"
            : "ESPN {$mode}: {$result['success']} matches updated, {$result['linked']} linked, {$result['created']} created, "
                .count($result['unmatched'])." unmatched, {$result['requests']} requests";
        $result['message'] .= $result['paused_until'] ? ' (feed paused)' : '';
        $this->result = ['success' => 0, 'errors' => 0, 'requests' => 0, 'created' => 0, 'linked' => 0, 'differences' => 0, 'unmatched' => []];

        return $result;
    }
}
