<?php

namespace App\Services;

use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Support\PlayerNames;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Player photos from API-Football: one call lists a league's teams, one call per team lists its
 * squad with a photo for every player. Photos are saved under public/generated/players/ and served
 * as static files (AGENTS rule 4), so pages and the stream never depend on API-Football's image
 * host. Players missing from our squads (football-data.org's free tier lists few leagues) are
 * added from API-Football so a scorer always has a photo.
 *
 * Calls share API-Football's daily budget with ApiFootballEnrichmentService (same counter).
 */
class PlayerPhotos
{
    private int $calls = 0;
    private ?string $error = null;
    private bool $outOfBudget = false;
    private ?float $lastCall = null;

    public function configured(): bool
    {
        return (string) config('services.api_football.key') !== '';
    }

    /**
     * Refreshes the squads whose photos are oldest (never, or over a week ago), at most $teams per run.
     *
     * @return array{teams: int, photos: int, added: int, calls: int}
     */
    public function sync(int $teams = 8): array
    {
        $result = ['teams' => 0, 'photos' => 0, 'added' => 0, 'calls' => 0, 'error' => null];
        if (!$this->configured()) {
            return $result;
        }
        foreach (config('services.api_football.photo_leagues', []) as $code => $apiLeague) {
            $this->linkTeams($code, (int) $apiLeague);
        }
        $due = Team::all()
            ->filter(fn (Team $t) => data_get($this->meta($t), 'api_football_team_id'))
            ->filter(fn (Team $t) => !($at = data_get($this->meta($t), 'api_football_photos_at')) || now()->diffInDays($at, true) >= 7)
            ->sortBy(fn (Team $t) => data_get($this->meta($t), 'api_football_photos_at') ?? '')
            ->take($teams);

        foreach ($due as $team) {
            $squad = $this->fetch('players/squads', ['team' => data_get($this->meta($team), 'api_football_team_id')]);
            if ($squad === null) {
                if ($this->outOfBudget) {
                    break; // today's calls are spent; tomorrow's run carries on
                }
                continue; // this squad was refused; the others still go
            }
            [$photos, $added] = $this->storeSquad($team, data_get($squad, '0.players', []));
            $meta = $this->meta($team);
            $meta['api_football_photos_at'] = now()->toIso8601String();
            $team->update(['metadata' => $meta]);
            $result['teams']++;
            $result['photos'] += $photos;
            $result['added'] += $added;
        }
        $result['calls'] = $this->calls;
        $result['error'] = $this->error;

        return $result;
    }

    /**
     * Saves a photo for a player (from any API-Football response) and points photo_url at our
     * copy. Returns false when the image could not be fetched; the player keeps what they had.
     */
    public function store(Player $player, ?string $source, ?int $apiId = null): bool
    {
        if (!$source || !preg_match('#^https://#', $source)) {
            return false;
        }
        $meta = is_array($player->metadata) ? $player->metadata : [];
        $file = "generated/players/{$player->id}.png";
        $fresh = ($meta['photo_source'] ?? null) === $source && is_file(public_path($file));
        if (!$fresh) {
            try {
                $response = Http::timeout(20)->get($source);
                if (!$response->successful() || !str_starts_with((string) $response->header('Content-Type'), 'image/') || strlen($response->body()) < 500) {
                    return false;
                }
                File::ensureDirectoryExists(public_path('generated/players'));
                File::put(public_path($file).'.part', $response->body());
                File::move(public_path($file).'.part', public_path($file));
            } catch (\Throwable $e) {
                Log::warning('Player photo download failed', ['player' => $player->id, 'error' => $e->getMessage()]);

                return false;
            }
        }
        $meta['photo_source'] = $source;
        if ($apiId) {
            $meta['api_football_player_id'] = $apiId;
        }
        $player->update(['photo_url' => asset($file).'?v='.substr(sha1($source.filesize(public_path($file))), 0, 8), 'metadata' => $meta]);

        return true;
    }

    /** Our photo for a player named in a match feed, or null. Includes players who left the squad. */
    public static function forName(?int $teamId, ?string $name): ?string
    {
        return self::player($teamId, $name)?->photo_url;
    }

    /**
     * The photo of a player named in a match moment: ['url' => …, 'path' => local file or null].
     * Looks in the side's squad first, then the other one (an own goal counts for the other side).
     */
    public static function forMatch(\App\Models\FootballMatch $match, ?string $side, ?string $name): ?array
    {
        $teams = $side === 'away' ? [$match->away_team_id, $match->home_team_id] : [$match->home_team_id, $match->away_team_id];
        foreach ($teams as $teamId) {
            if ($player = self::player($teamId, $name)) {
                $file = public_path("generated/players/{$player->id}.png");

                return ['url' => $player->photo_url, 'path' => is_file($file) ? $file : null, 'name' => $player->name];
            }
        }

        return null;
    }

    private static function player(?int $teamId, ?string $name): ?Player
    {
        if (!$teamId || !$name || trim($name) === '') {
            return null;
        }

        return Player::where('team_id', $teamId)->whereNotNull('photo_url')->get()
            ->sortByDesc('is_active')->first(fn (Player $p) => PlayerNames::same($p->name, $name));
    }

    /** Stores API-Football's team id on our teams (one call per league a week). */
    private function linkTeams(string $code, int $apiLeague): void
    {
        $league = League::where('short_code', $code)->orWhere('espn_slug', $code)->first();
        if (!$league) {
            return;
        }
        $ids = $league->matches()->pluck('home_team_id')->merge($league->matches()->pluck('away_team_id'))->filter()->unique();
        $teams = Team::whereIn('id', $ids)->get();
        $unlinked = $teams->filter(fn (Team $t) => !data_get($this->meta($t), 'api_football_team_id'));
        if ($unlinked->isEmpty()) {
            return;
        }
        $season = now()->month >= 7 ? now()->year : now()->year - 1;
        foreach ($this->fetch('teams', ['league' => $apiLeague, 'season' => $season]) ?? [] as $row) {
            $this->linkTeam($unlinked, $row);
        }
        // The league list needs a season the plan covers (the free plan stops short of the current
        // one); club search doesn't, so look up whoever is still unlinked by name instead.
        foreach ($unlinked->filter(fn (Team $t) => !data_get($this->meta($t->fresh()), 'api_football_team_id')) as $team) {
            $name = trim(preg_replace('/\b(a?fc)\b/i', '', (string) ($team->common_name ?: $team->name)));
            foreach ($this->searchTerms($name) as $term) {
                $found = $this->fetch('teams', ['search' => $term]);
                if ($found === null && $this->outOfBudget) {
                    return; // today's calls are spent; tomorrow's run carries on
                }
                // An exact name first: "Liverpool" also finds "Liverpool Montevideo".
                $clubs = collect($found ?? [])->reject(fn ($r) => data_get($r, 'team.national'));
                $row = $clubs->first(fn ($r) => $this->clubKey((string) data_get($r, 'team.name')) === $this->clubKey($name))
                    ?? $clubs->first(fn ($r) => $this->sameClub($team, (string) data_get($r, 'team.name')));
                if ($row) {
                    $this->linkTeam(collect([$team]), $row);
                    break;
                }
            }
        }
    }

    /**
     * What to search API-Football for: its search takes only letters, digits and spaces, so
     * "Brighton & Hove Albion" becomes "Brighton Hove Albion", then just "Brighton" if that finds nothing.
     *
     * @return array<int, string>
     */
    private function searchTerms(string $name): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ]+/', ' ', Str::ascii($name))));
        $first = collect(explode(' ', $clean))->first(fn ($w) => strlen($w) >= 4);

        return array_values(array_unique(array_filter([mb_substr($clean, 0, 40), $first], fn ($t) => $t && strlen($t) >= 3)));
    }

    private function clubKey(string $name): string
    {
        return trim(preg_replace('/\b(a?fc|cf|sc)\b/', '', PlayerNames::normalize($name)));
    }

    private function linkTeam(Collection $teams, array $row): void
    {
        $team = $teams->count() === 1 ? $teams->first() : $teams->first(fn (Team $t) => $this->sameClub($t, (string) data_get($row, 'team.name')));
        if ($team) {
            $meta = $this->meta($team);
            $meta['api_football_team_id'] = (int) data_get($row, 'team.id');
            $team->update(['metadata' => $meta]);
        }
    }

    /** @return array{0: int, 1: int} photos saved, players added */
    private function storeSquad(Team $team, array $squad): array
    {
        $players = Player::where('team_id', $team->id)->get();
        $photos = 0;
        $added = 0;
        foreach ($squad as $row) {
            $name = trim((string) data_get($row, 'name'));
            $apiId = (int) data_get($row, 'id');
            if ($name === '' || !$apiId) {
                continue;
            }
            $player = $players->first(fn (Player $p) => (int) data_get($p->metadata, 'api_football_player_id') === $apiId)
                ?? $players->first(fn (Player $p) => PlayerNames::same($p->name, $name));
            if (!$player) {
                $number = data_get($row, 'number');
                $taken = $number !== null && $players->contains(fn (Player $p) => $p->shirt_number === (int) $number);
                $player = Player::create([
                    'team_id' => $team->id, 'name' => $name, 'position' => data_get($row, 'position'),
                    'shirt_number' => $taken ? null : $number, 'age' => data_get($row, 'age'), 'is_active' => true,
                    'metadata' => ['api_football_player_id' => $apiId, 'source' => 'api-football'],
                ]);
                $players->push($player);
                $added++;
            }
            $photos += $this->store($player, data_get($row, 'photo'), $apiId) ? 1 : 0;
        }

        return [$photos, $added];
    }

    private function sameClub(Team $team, string $name): bool
    {
        $strip = fn (string $v) => trim(preg_replace('/\b(a?fc|cf|sc)\b/', '', PlayerNames::normalize($v)));
        $a = $strip($team->name);
        $b = $strip($name);
        $c = $strip((string) $team->common_name);

        return $a !== '' && $b !== '' && ($a === $b || $c === $b || str_starts_with($a, "{$b} ") || str_starts_with($b, "{$a} ")
            || ($c !== '' && (str_starts_with($c, "{$b} ") || str_starts_with($b, "{$c} "))));
    }

    /** One API-Football call, cached for a week; null when today's budget is spent or the call failed. */
    private function fetch(string $endpoint, array $query): ?array
    {
        $key = 'api-football:'.sha1($endpoint.'|'.json_encode($query));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $usage = 'api-football:daily-calls:'.now()->toDateString();
        if ((int) Cache::get($usage, 0) >= (int) config('services.api_football.daily_budget', 95)) {
            $this->outOfBudget = true;

            return null;
        }
        // The free plan allows about 10 calls a minute: space calls out (API_FOOTBALL_MIN_INTERVAL_MS).
        $gap = (int) config('services.api_football.min_interval_ms', 6500) / 1000;
        if ($this->lastCall !== null && ($wait = $this->lastCall + $gap - microtime(true)) > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastCall = microtime(true);
        /** @var Response $response */
        $response = Http::timeout(35)->withHeaders(['x-apisports-key' => config('services.api_football.key')])
            ->get(rtrim(config('services.api_football.url'), '/').'/'.$endpoint, $query);
        $this->calls++;
        Cache::put($usage, (int) Cache::get($usage, 0) + 1, now()->endOfDay());
        if (!$response->successful() || !empty($response->json('errors'))) {
            $errors = $response->json('errors') ?: 'HTTP '.$response->status();
            $this->error = 'API-Football '.$endpoint.': '.(is_string($errors) ? $errors : json_encode($errors));
            Log::warning($this->error);
            if (is_array($errors) && (isset($errors['rateLimit']) || isset($errors['requests']))) {
                $this->outOfBudget = true; // told to slow down or out of requests: stop, tomorrow's run carries on
            }

            return null;
        }
        $data = $response->json('response', []);
        $data = is_array($data) ? $data : [];
        Cache::put($key, $data, now()->addDays(empty($data) ? 1 : 7));

        return $data;
    }

    private function meta(Team $team): array
    {
        $meta = $team->metadata;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) ? $meta : [];
    }
}
