<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\MatchMoment;
use App\Support\Armies;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Makes the Griot's 20-second full-time reel: builds the story (props) from a match's chapters,
 * then renders it with Remotion on this server or hands it to GitHub Actions. Progress is kept
 * in the match metadata under `reel` (status: rendering | dispatched | ready | failed).
 */
class ReelService
{
    public function __construct(private MatchStoryService $stories, private Griot $griot) {}

    /** Finished matches whose full-time chapter is old enough and that have no reel yet. */
    public function pending(): \Illuminate\Support\Collection
    {
        return FootballMatch::with(['league', 'homeTeam', 'awayTeam'])
            ->whereIn('status', ['FINISHED', 'FT'])
            ->where('match_date', '>=', now('UTC')->subDays(2))
            ->whereIn('id', MatchMoment::where('type', 'full_time')->where('created_at', '<=', now()->subMinutes(config('reel.delay_minutes')))->select('match_id'))
            ->orderBy('match_date')
            ->get()
            ->filter(fn ($match) => $this->stories->tellsStory($match) && !in_array(data_get($match->metadata, 'reel.status'), ['ready', 'rendering', 'dispatched'], true));
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
            'title' => ['headline' => "{$home['army']} v {$away['army']}", 'line' => "{$home['club']} v {$away['club']}"],
            'home' => $home,
            'away' => $away,
            'beats' => $chapters->whereNotIn('type', ['full_time'])->map(function (MatchMoment $chapter) use ($voice) {
                [$headline, $line] = $this->griot->tell($chapter, $voice);

                return ['type' => $chapter->type, 'tag' => $this->griot->tag($chapter), 'side' => $chapter->side,
                    'home_score' => $chapter->home_score, 'away_score' => $chapter->away_score, 'headline' => $headline, 'line' => $line];
            })->values()->all(),
            'stats' => [
                ['label' => 'POSSESSION %', 'home' => $match->home_possession, 'away' => $match->away_possession],
                ['label' => 'SHOTS', 'home' => $match->home_shots, 'away' => $match->away_shots],
                ['label' => 'ON TARGET', 'home' => $match->home_shots_on_target, 'away' => $match->away_shots_on_target],
            ],
            'final' => $final ? array_combine(['headline', 'line'], $this->griot->tell($final, $voice)) : ['headline' => 'Full time', 'line' => ''],
        ];
    }

    public function make(FootballMatch $match): string
    {
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
        File::put($propsFile, json_encode($this->props($match), JSON_UNESCAPED_UNICODE));

        $process = new Process([config('reel.node'), base_path('reel/render.mjs'), '--props', $propsFile, '--out', $out], base_path('reel'), null, null, config('reel.timeout'));
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
            } elseif (now()->diffInMinutes(data_get($match->metadata, 'reel.started_at')) > 45) {
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
