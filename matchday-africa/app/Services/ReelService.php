<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\MatchMoment;
use App\Support\Armies;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Makes the Griot's 20-second full-time reel: builds the story (props) from a match's chapters,
 * then renders it with Remotion on this server or hands it to GitHub Actions. Progress is kept
 * in the match metadata under `reel` (status: rendering | dispatched | ready | failed).
 */
class ReelService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private MatchStoryService $stories, private Griot $griot) {}

    /** Finished matches whose full-time chapter is old enough and that have no reel yet. */
    public function pending(): \Illuminate\Support\Collection
    {
        return FootballMatch::with(['league', 'homeTeam', 'awayTeam'])
            ->whereIn('status', ['FINISHED', 'FT'])
            // Recent matches, plus any older one queued by hand (reel:queue or the admin reels page).
            ->where(fn ($q) => $q->where('match_date', '>=', now('UTC')->subDays(2))->orWhere('metadata->reel->status', 'queued'))
            ->whereIn('id', MatchMoment::where('type', 'full_time')->where('created_at', '<=', now()->subMinutes(config('reel.delay_minutes')))->select('match_id'))
            ->orderBy('match_date')
            ->get()
            ->filter(fn ($match) => $this->stories->tellsStory($match) && $this->due($match));
    }

    /**
     * Not made yet, or failed fewer than MAX_ATTEMPTS times, or a render that died mid-way
     * (still "rendering" well past the timeout). A failing match is not retried every minute forever.
     */
    private function due(FootballMatch $match): bool
    {
        $reel = data_get($match->metadata, 'reel', []);
        $stale = ($reel['status'] ?? null) === 'rendering' && isset($reel['started_at'])
            && Carbon::parse($reel['started_at'])->diffInMinutes(now()) > config('reel.timeout') / 60 + 10;

        return ($reel['attempts'] ?? 0) < self::MAX_ATTEMPTS
            && (!in_array($reel['status'] ?? null, ['ready', 'rendering', 'dispatched'], true) || $stale);
    }

    /** The reel's story as Remotion props (see reel/src/sample.json). Art is a file path or a URL. */
    public function props(FootballMatch $match, bool $urls = false): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam']);
        $voice = config('reel.voice');
        $club = fn ($team) => trim(preg_replace('/\s+FC$/i', '', (string) $team?->name));
        $side = function ($team, int $score) use ($club, $urls) {
            $army = Armies::for($team);

            return [
                'club' => $club($team), 'army' => $army['army'] ?? $club($team), 'colours' => $army['colours'] ?? ['#d2a052', '#e8e1d3'], 'score' => $score,
                $urls ? 'art_url' : 'art_path' => $urls ? $army['cutout'] : $army['cutout_path'],
            ];
        };
        $chapters = $this->stories->chapters($match);
        $final = $chapters->firstWhere('type', 'full_time');
        $home = $side($match->homeTeam, (int) $match->home_score);
        $away = $side($match->awayTeam, (int) $match->away_score);

        return [
            'format' => 'story',
            'competition' => 'PREMIER LEAGUE',
            'title' => ['headline' => "{$home['army']} v {$away['army']}", 'line' => "{$home['club']} v {$away['club']}",
                'say' => ["{$home['club']} against {$away['club']}."]],
            'home' => $home,
            'away' => $away,
            'beats' => $chapters->whereNotIn('type', ['full_time'])->map(function (MatchMoment $chapter) use ($voice, $home, $away) {
                [$headline, $line] = $this->griot->tell($chapter, $voice);

                return ['type' => $chapter->type, 'tag' => $this->griot->tag($chapter), 'side' => $chapter->side,
                    'home_score' => $chapter->home_score, 'away_score' => $chapter->away_score, 'headline' => $headline, 'line' => $line,
                    'say' => $this->say($chapter, $home['club'], $away['club'])];
            })->values()->all(),
            'stats' => [
                ['label' => 'POSSESSION %', 'home' => $match->home_possession, 'away' => $match->away_possession],
                ['label' => 'SHOTS', 'home' => $match->home_shots, 'away' => $match->away_shots],
                ['label' => 'ON TARGET', 'home' => $match->home_shots_on_target, 'away' => $match->away_shots_on_target],
            ],
            'final' => ($final ? array_combine(['headline', 'line'], $this->griot->tell($final, $voice)) : ['headline' => 'Full time', 'line' => ''])
                + ['say' => ["Full time. {$home['club']} {$home['score']}, {$away['club']} {$away['score']}.", 'Full time.']],
        ];
    }

    /**
     * The commentary for one chapter, longest wording first: radio/speak.mjs uses the longest that
     * fits the scene (a beat lasts 2.8 to 14 seconds). Only facts from the chapter: who, when, the
     * score, called the way a commentator would ("Saka scores! … 1 nil").
     */
    private function say(MatchMoment $chapter, string $home, string $away): array
    {
        $score = "{$home} {$chapter->home_score}, {$away} {$chapter->away_score}.";
        $short = implode(' ', array_map(fn ($n) => $n === 0 ? 'nil' : (string) $n, [(int) $chapter->home_score, (int) $chapter->away_score])).'.';
        $team = ['home' => $home, 'away' => $away][$chapter->side] ?? null;
        $name = $chapter->player_name ? Str::afterLast(trim($chapter->player_name), ' ') : null;
        $when = $chapter->minute ? $chapter->minute.($chapter->extra_minute ? " plus {$chapter->extra_minute}" : '').' minutes' : null;
        $scores = $name ? "{$name} scores" : ($team ? "{$team} score" : null);

        return array_values(array_filter(match ($chapter->type) {
            'goal' => [$scores && $when ? "{$scores}, {$when}! {$score}" : null, $scores ? "{$scores}! {$score}" : null,
                $name ? "Goal, {$name}! {$short}" : "Goal! {$short}", $scores ? "{$scores}!" : null, 'Goal!'],
            'red_card' => [$name && $when ? "Red card! {$name} is off, {$when}." : null, $name ? "Red card, {$name}!" : null, 'Red card!'],
            'kickoff' => ["Kick-off. {$home} against {$away}.", 'Kick-off.'],
            'half_time' => ["Half-time. {$score}", "Half-time, {$short}", 'Half-time.'],
            default => [],
        }));
    }

    public function make(FootballMatch $match): string
    {
        if (config('reel.renderer') !== 'off') {
            $this->mark($match, ['attempts' => data_get($match->fresh()?->metadata, 'reel.attempts', 0) + 1]);
        }

        return match (config('reel.renderer')) {
            'server' => $this->renderHere($match),
            'github' => $this->dispatchToGithub($match),
            default => 'off',
        };
    }

    /** Renders on this host with Node; blocks for the length of the render (about 1–5 minutes). */
    public function renderHere(FootballMatch $match): string
    {
        $this->mark($match, ['status' => 'rendering', 'started_at' => now()->toIso8601String()]);
        $dir = storage_path('app/reels');
        File::ensureDirectoryExists($dir);
        $propsFile = "{$dir}/{$match->id}.json";
        $out = "{$dir}/{$match->id}.mp4";
        $poster = "{$dir}/{$match->id}.jpg";
        $props = $this->props($match);
        $voiceDir = "{$dir}/{$match->id}-voice";
        if (config('reel.narration')) {
            File::put($propsFile, json_encode($props, JSON_UNESCAPED_UNICODE));
            $props['narration'] = $this->speak($propsFile, $voiceDir, $match);
        }
        File::put($propsFile, json_encode($props, JSON_UNESCAPED_UNICODE));

        $process = new Process([config('reel.node'), base_path('reel/render.mjs'), '--props', $propsFile, '--out', $out, '--poster', $poster], base_path('reel'), null, null, config('reel.timeout'));
        try {
            $process->mustRun();
            $this->mark($match, ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]);

            return 'ready';
        } catch (Throwable $e) {
            Log::error('Reel render failed', ['match_id' => $match->id, 'error' => $e->getMessage()]);
            $this->mark($match, ['status' => 'failed', 'error' => mb_substr($process->getErrorOutput() ?: $e->getMessage(), 0, 500)]);

            return 'failed';
        } finally {
            File::delete($propsFile);
            File::deleteDirectory($voiceDir);
        }
    }

    /**
     * Voices the commentary with Kokoro (radio/speak.mjs, run with the radio's Node) and returns the
     * lines for render.mjs to mix in. A failure leaves the reel without commentary, never without a reel.
     */
    private function speak(string $propsFile, string $voiceDir, FootballMatch $match): array
    {
        $process = new Process([config('radio.node'), base_path('radio/speak.mjs'), '--props', $propsFile, '--out-dir', $voiceDir], base_path('radio'),
            ['RADIO_TTS_CACHE' => config('radio.cache'), 'REEL_NARRATION_VOICE' => config('reel.narration_voice')], null, config('reel.timeout'));
        try {
            $process->mustRun();
            $lines = json_decode(collect(explode("\n", trim($process->getOutput())))->last() ?? '', true);
            if (!is_array($lines)) {
                throw new \RuntimeException('No commentary lines returned');
            }

            return array_values(array_filter($lines, fn ($line) => is_array($line) && is_numeric($line['at'] ?? null)
                && is_string($line['file'] ?? null) && str_starts_with(realpath($line['file']) ?: '', realpath($voiceDir) ?: "\0")));
        } catch (Throwable $e) {
            Log::warning('Reel commentary failed; rendering without it', ['match_id' => $match->id, 'error' => mb_substr($process->getErrorOutput() ?: $e->getMessage(), 0, 500)]);

            return [];
        }
    }

    /** Asks GitHub Actions to render; the workflow fetches the props from a signed URL. */
    public function dispatchToGithub(FootballMatch $match): string
    {
        $github = config('reel.github');
        if (!$github['token']) {
            $this->mark($match, ['status' => 'failed', 'error' => 'REEL_GITHUB_TOKEN is not set']);

            return 'failed';
        }
        $response = Http::withToken($github['token'])->acceptJson()
            ->post("https://api.github.com/repos/{$github['repo']}/actions/workflows/{$github['workflow']}/dispatches", [
                'ref' => $github['ref'],
                'inputs' => ['match_id' => (string) $match->id, 'props_url' => URL::temporarySignedRoute('reels.props', now()->addHours(2), $match)],
            ]);
        if ($response->failed()) {
            $this->mark($match, ['status' => 'failed', 'error' => "GitHub {$response->status()}: ".mb_substr($response->body(), 0, 300)]);

            return 'failed';
        }
        $this->mark($match, ['status' => 'dispatched', 'source' => 'github', 'started_at' => now()->toIso8601String()]);

        return 'dispatched';
    }

    /** GitHub uploads by FTP; once the file lands, the reel is ready. */
    public function collectUploads(): int
    {
        $found = 0;
        $matches = FootballMatch::where('match_date', '>=', now('UTC')->subDays(2))->whereNotNull('metadata')->get()
            ->filter(fn ($match) => data_get($match->metadata, 'reel.status') === 'dispatched');
        foreach ($matches as $match) {
            if (is_file($this->publicPath($match))) {
                $this->mark($match, ['status' => 'ready', 'ready_at' => now()->toIso8601String()]);
                $found++;
            } elseif (Carbon::parse(data_get($match->metadata, 'reel.started_at'))->diffInMinutes(now()) > 45) {
                $this->mark($match, ['status' => 'failed', 'error' => 'GitHub render did not arrive within 45 minutes']);
            }
        }

        return $found;
    }

    public function path(FootballMatch $match): ?string
    {
        $file = data_get($match->metadata, 'reel.source') === 'github' ? $this->publicPath($match) : storage_path("app/reels/{$match->id}.mp4");

        return data_get($match->metadata, 'reel.status') === 'ready' && is_file($file) ? $file : null;
    }

    /**
     * Queues a reel for (re)rendering by the scheduler: rebuilds the match's chapters first
     * (older matches predate them) and resets its attempts. Returns false when the match can't
     * have a reel (not a finished Premier League match between two clubs with art).
     */
    public function queue(FootballMatch $match): bool
    {
        if (!in_array($match->status, ['FINISHED', 'FT'], true) || !$this->stories->tellsStory($match)) {
            return false;
        }
        $this->stories->sync($match);
        MatchMoment::where('match_id', $match->id)->where('type', 'full_time')->update(['created_at' => now()->subMinutes(config('reel.delay_minutes') + 1)]);
        $this->mark($match, ['status' => 'queued', 'attempts' => 0, 'queued_at' => now()->toIso8601String()]);

        return true;
    }

    /** How strong a reel is: 10, plus goals, a late goal, red cards and the biggest clubs. */
    public function weight(FootballMatch $match): int
    {
        $match->loadMissing(['homeTeam', 'awayTeam']);
        $moments = MatchMoment::where('match_id', $match->id)->get();
        $big = collect([$match->homeTeam?->name, $match->awayTeam?->name])
            ->filter(fn ($n) => in_array(Armies::key((string) $n), config('buffer.big_clubs'), true))->count();

        return 10 + 3 * ((int) $match->home_score + (int) $match->away_score)
            + ($moments->where('type', 'goal')->where('minute', '>=', 85)->isNotEmpty() ? 8 : 0)
            + 4 * $moments->where('type', 'red_card')->count() + 6 * $big;
    }

    /**
     * Where the reel plays from: a static copy in the web root. The host serves static files with
     * byte ranges, which iPhones and Buffer need, but drops them from anything PHP sends.
     * Falls back to the PHP route if the copy can't be made.
     */
    public function url(FootballMatch $match): ?string
    {
        $file = $this->path($match);
        if (!$file) {
            return null;
        }
        $readyAt = data_get($match->metadata, 'reel.ready_at');
        $version = ['v' => $readyAt ? Carbon::parse($readyAt)->timestamp : filemtime($file)];
        $public = $this->publicPath($match);
        try {
            if ($file !== $public && (!is_file($public) || filesize($public) !== filesize($file) || filemtime($public) < filemtime($file))) {
                File::ensureDirectoryExists(dirname($public));
                File::copy($file, "{$public}.part");
                rename("{$public}.part", $public);
            }

            return asset("generated/reels/{$match->id}/{$match->id}.mp4").'?'.http_build_query($version);
        } catch (Throwable $e) {
            Log::warning('Reel could not be published as a static file', ['match_id' => $match->id, 'error' => $e->getMessage()]);

            return route('matches.reel', ['match' => $match] + $version);
        }
    }

    /** The reel's poster frame, when the render made one. */
    public function posterPath(FootballMatch $match): ?string
    {
        $video = $this->path($match);
        $poster = $video ? preg_replace('/\.mp4$/', '.jpg', $video) : null;

        return $poster && is_file($poster) ? $poster : null;
    }

    private function publicPath(FootballMatch $match): string
    {
        return public_path("generated/reels/{$match->id}/{$match->id}.mp4");
    }

    private function mark(FootballMatch $match, array $reel): void
    {
        // Re-read first: the live sync may have written other metadata while this render ran.
        $meta = $match->fresh()?->metadata;
        $meta = is_array($meta) ? $meta : [];
        $meta['reel'] = array_merge($meta['reel'] ?? [], $reel);
        if (($reel['status'] ?? null) !== 'failed') {
            unset($meta['reel']['error']);
        }
        $match->forceFill(['metadata' => $meta])->save();
    }
}
