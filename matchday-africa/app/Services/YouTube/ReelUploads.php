<?php

namespace App\Services\YouTube;

use App\Jobs\UploadReelToYouTube;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\MatchMoment;
use App\Models\YoutubeAccount;
use App\Services\ReelService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Every new reel as a YouTube Short on the Matchday Africa channel.
 *
 * A reel that becomes ready is marked pending (youtube_status) and a queued UploadReelToYouTube
 * job is sent; youtube:uploads (every 5 minutes) uploads whatever is still pending, so uploads
 * also go out where no queue worker runs. Both claim the match first, so a reel goes up once.
 * Uploads are resumable; a failed one is retried three times (config youtube.uploads.retry_minutes)
 * and resumes its upload session. At most daily_uploads go up per YouTube quota day; the rest wait.
 */
class ReelUploads
{
    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    /** Minutes after which an upload still marked "uploading" is taken to have died. */
    private const STALE_MINUTES = 30;

    public function __construct(private YouTubeClient $client, private ReelService $reels) {}

    /** The columns exist (migration run). */
    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasColumn('matches', 'youtube_status');
    }

    /** Switched on, the columns exist, and a channel that may upload is connected (the right one, when YOUTUBE_CHANNEL_ID is set). */
    public function enabled(): bool
    {
        return config('youtube.uploads.enabled') && self::ready() && $this->account() !== null;
    }

    public function account(): ?YoutubeAccount
    {
        if (!YouTubeClient::configured() || !($account = YouTubeClient::account()) || !YouTubeClient::canUpload($account)) {
            return null;
        }
        $wanted = (string) config('youtube.channel_id');

        return $wanted === '' || $account->channel_id === $wanted ? $account : null;
    }

    /** Called when a reel becomes ready: queue it unless it is already on YouTube. */
    public function reelReady(FootballMatch $match): void
    {
        if (!$this->enabled() || $this->queue($match) === false) {
            return;
        }
        UploadReelToYouTube::dispatch($match->id);
    }

    /**
     * Marks a match's reel to be uploaded (again, from scratch on its attempts). False when it is
     * already on YouTube or has no ready reel.
     */
    public function queue(FootballMatch $match): bool
    {
        $match = $match->fresh() ?? $match;
        if ($match->youtube_video_id || !$this->reels->path($match)) {
            return false;
        }
        if ($match->youtube_status === 'uploading' && !$this->stale($match)) {
            return true;
        }
        $match->forceFill(['youtube_status' => 'pending', 'youtube_attempts' => 0, 'youtube_next_at' => null, 'youtube_error' => null])->save();

        return true;
    }

    /** Pending uploads that are due, newest match first. */
    public function due(): Collection
    {
        if (!self::ready()) {
            return collect();
        }

        return FootballMatch::with(['league', 'homeTeam', 'awayTeam'])
            ->whereNull('youtube_video_id')
            // An upload still "uploading" past its deadline (youtube_next_at) died mid-way: due again, and it resumes.
            ->whereIn('youtube_status', ['pending', 'uploading'])
            ->where(fn ($q) => $q->whereNull('youtube_next_at')->orWhere('youtube_next_at', '<=', $this->clock()))
            ->orderByDesc('match_date')
            ->get();
    }

    /** Uploads every due reel the day's quota allows. Returns [uploaded, still waiting]. */
    public function run(): array
    {
        $uploaded = 0;
        $due = $this->enabled() ? $this->due() : collect();
        foreach ($due as $match) {
            if ($this->left() <= 0) {
                break;
            }
            $uploaded += $this->upload($match) === 'uploaded' ? 1 : 0;
        }

        return [$uploaded, $this->due()->count()];
    }

    /**
     * Uploads one match's reel: uploaded, waiting (quota or not due), skipped (already on YouTube
     * or no reel), retry or failed.
     */
    public function upload(FootballMatch $match): string
    {
        if (!$this->enabled() || !($account = $this->account())) {
            return 'skipped';
        }
        $match = $match->fresh(['league', 'homeTeam', 'awayTeam']) ?? $match;
        if ($match->youtube_video_id) {
            return 'skipped';
        }
        $file = $this->reels->path($match);
        if (!$file) {
            $match->forceFill(['youtube_status' => 'failed', 'youtube_error' => 'The reel file is missing.'])->save();

            return 'failed';
        }
        $session = $match->youtube_upload_session;
        $resuming = is_array($session) && ($session['size'] ?? null) === filesize($file);
        if (!$resuming && $this->left() <= 0) {
            return 'waiting';
        }
        if (!$this->claim($match)) {
            return 'skipped';
        }

        try {
            $video = $this->send($account, $match, $file, $resuming ? $session['url'] : null);
        } catch (QuotaExhausted $e) {
            // Not the reel's fault: wait for the new quota day, without using up an attempt.
            Cache::put($this->exhaustedKey(), true, $this->quotaReset());
            $match->forceFill(['youtube_status' => 'pending', 'youtube_next_at' => $this->quotaReset(), 'youtube_error' => $e->getMessage()])->save();
            Log::warning('YouTube upload quota used up; reels wait for the next quota day', ['match_id' => $match->id]);

            return 'waiting';
        } catch (Throwable $e) {
            return $this->failed($match, $e);
        }

        $match->forceFill([
            'youtube_video_id' => $video['id'], 'youtube_uploaded_at' => $this->clock(), 'youtube_status' => 'uploaded',
            'youtube_error' => $this->restriction($video), 'youtube_next_at' => null, 'youtube_upload_session' => null,
        ])->save();
        Log::info('Reel uploaded to YouTube', ['match_id' => $match->id, 'video_id' => $video['id']]);

        return 'uploaded';
    }

    /** Uploads left today (YouTube's quota day, Pacific time). */
    public function left(): int
    {
        if (Cache::get($this->exhaustedKey())) {
            return 0;
        }

        return max(0, (int) config('youtube.uploads.daily_uploads') - (int) Cache::get($this->countKey(), 0));
    }

    public function usedToday(): int
    {
        return (int) Cache::get($this->countKey(), 0);
    }

    public function quotaReset(): Carbon
    {
        return now(config('youtube.uploads.quota_timezone'))->addDay()->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** "Arsenal 2-1 Chelsea | Saka opens the scoring, 12' #Shorts" (at most 100 characters). */
    public function title(FootballMatch $match): string
    {
        [$home, $away] = [$this->name($match->homeTeam), $this->name($match->awayTeam)];
        $head = "{$home} ".(int) $match->home_score.'-'.(int) $match->away_score." {$away}";
        $tail = ' #Shorts';
        $hook = $this->hook($match);
        $room = 100 - mb_strlen($head) - mb_strlen($tail) - 3;
        $title = $room >= 8 ? $head.' | '.Str::limit($hook, $room - 1, '…').$tail : Str::limit($head, 100 - mb_strlen($tail), '…').$tail;

        // YouTube refuses titles containing angle brackets.
        return str_replace(['<', '>'], '', $title);
    }

    /** "Points shared" for a draw, else who scored first (from the match's own goal data). */
    public function hook(FootballMatch $match): string
    {
        if ((int) $match->home_score === (int) $match->away_score) {
            return 'Points shared';
        }
        $first = MatchMoment::where('match_id', $match->id)->where('type', 'goal')
            ->orderBy('minute')->orderBy('extra_minute')->orderBy('id')->first();
        if (!$first) {
            $winner = (int) $match->home_score > (int) $match->away_score ? $match->homeTeam : $match->awayTeam;

            return $this->name($winner).' win';
        }
        $minute = $first->minute !== null ? ', '.$first->minute.($first->extra_minute ? '+'.$first->extra_minute : '')."'" : '';
        $ownGoal = $first->match_event_id && MatchEvent::whereKey($first->match_event_id)->value('is_own_goal');
        $player = trim((string) $first->player_name);
        if ($player === '') {
            $team = $first->side === 'home' ? $match->homeTeam : $match->awayTeam;

            return $this->name($team).' score first'.$minute;
        }

        return ($ownGoal ? "{$player} own goal opens the scoring" : "{$player} opens the scoring").$minute;
    }

    public function description(FootballMatch $match): string
    {
        [$home, $away] = [$this->name($match->homeTeam), $this->name($match->awayTeam)];
        [$h, $a] = [(int) $match->home_score, (int) $match->away_score];
        $competition = $match->league?->name ? ' in the '.preg_replace('/^the\s+/i', '', $match->league->name) : '';
        $day = Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone('Africa/Lagos')->format('j F Y');
        $summary = match (true) {
            $h > $a => "{$home} beat {$away} {$h}-{$a}{$competition} on {$day}.",
            $a > $h => "{$away} won {$a}-{$h} at {$home}{$competition} on {$day}.",
            default => "{$home} and {$away} drew {$h}-{$a}{$competition} on {$day}.",
        };
        $link = rtrim((string) config('app.url'), '/')."/matches/{$match->id}?".http_build_query(['utm_source' => 'youtube', 'utm_medium' => 'social', 'utm_campaign' => 'reels']);

        return $summary."\n\nWatch the full match story: {$link}\n\n".trim('#MatchdayAfrica '.$this->competitionTag($match));
    }

    public function competitionTag(FootballMatch $match): string
    {
        $tags = (array) config('buffer.x.competition_tags');
        $tag = $tags[$match->league?->espn_slug] ?? $tags[$match->league?->short_code] ?? null;
        if (!$tag && $match->league?->name) {
            $tag = '#'.preg_replace('/[^A-Za-z0-9]/', '', Str::studly(Str::ascii($match->league->name)));
        }

        return $tag && $tag !== '#' ? $tag : '';
    }

    /** The request body for videos.insert. */
    public function metadata(FootballMatch $match): array
    {
        $tags = array_values(array_filter(['Matchday Africa', $this->name($match->homeTeam), $this->name($match->awayTeam), $match->league?->name, 'football', 'Shorts']));

        return [
            'snippet' => [
                'title' => $this->title($match),
                'description' => $this->description($match),
                'categoryId' => (string) config('youtube.uploads.category'),
                'tags' => $tags,
                'defaultLanguage' => 'en',
            ],
            'status' => [
                'privacyStatus' => config('youtube.uploads.privacy'),
                'selfDeclaredMadeForKids' => false,
                'embeddable' => true,
            ],
        ];
    }

    /** Takes the match for this process; false when another upload of it is running. */
    private function claim(FootballMatch $match): bool
    {
        $claimed = FootballMatch::whereKey($match->id)->whereNull('youtube_video_id')
            ->where(fn ($q) => $q->whereNull('youtube_status')->orWhereIn('youtube_status', ['pending', 'failed'])
                ->orWhere(fn ($q) => $q->where('youtube_status', 'uploading')->where('youtube_next_at', '<=', $this->clock())))
            ->update(['youtube_status' => 'uploading', 'youtube_next_at' => $this->clock()->addMinutes(self::STALE_MINUTES)]);
        if ($claimed) {
            $match->refresh();
        }

        return $claimed === 1;
    }

    private function stale(FootballMatch $match): bool
    {
        return FootballMatch::whereKey($match->id)->where(fn ($q) => $q->whereNull('youtube_next_at')->orWhere('youtube_next_at', '<=', $this->clock()))->exists();
    }

    /** Resumable upload: starts (or resumes) the session and sends the file in chunks. Returns the video resource. */
    private function send(YoutubeAccount $account, FootballMatch $match, string $file, ?string $url): array
    {
        $size = filesize($file);
        $offset = 0;
        if ($url) {
            [$offset, $video] = $this->progress($account, $url, $size);
            if ($video) {
                return $video;
            }
            if ($offset === null) {
                $url = null; // The session has expired: start again.
                $offset = 0;
            }
        }
        if (!$url) {
            $response = Http::withToken($this->client->accessToken($account))->timeout(30)->acceptJson()
                ->withHeaders(['X-Upload-Content-Type' => 'video/mp4', 'X-Upload-Content-Length' => (string) $size])
                ->post(self::UPLOAD.'?'.http_build_query(['uploadType' => 'resumable', 'part' => 'snippet,status']), $this->metadata($match));
            $this->guard($response);
            $url = (string) $response->header('Location');
            if ($url === '') {
                throw new RuntimeException('YouTube did not return an upload session.');
            }
            Cache::put($this->countKey(), $this->usedToday() + 1, now()->addDays(2));
            $match->forceFill(['youtube_upload_session' => ['size' => $size, 'url' => $url]])->save();
        }

        $chunk = max(256 * 1024, (int) config('youtube.uploads.chunk_bytes'));
        $handle = fopen($file, 'rb');
        try {
            while (true) {
                fseek($handle, $offset);
                $bytes = fread($handle, $chunk);
                $end = $offset + strlen($bytes) - 1;
                $response = Http::withToken($this->client->accessToken($account))->timeout(120)->withoutRedirecting()
                    ->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$size}"])
                    ->withBody($bytes, 'video/mp4')->put($url);
                if ($response->successful() && $response->json('id')) {
                    return $response->json();
                }
                if ($response->status() !== 308) {
                    $this->guard($response);
                    throw new RuntimeException('YouTube upload stopped (HTTP '.$response->status().').');
                }
                $next = $this->rangeEnd($response->header('Range'));
                if ($next <= $offset && $end >= $offset) {
                    throw new RuntimeException('YouTube accepted none of the last chunk.');
                }
                $offset = $next;
            }
        } finally {
            fclose($handle);
        }
    }

    /** Where an earlier session got to: [bytes received, null], [null, video] when it finished, or [null, null] when it has gone. */
    private function progress(YoutubeAccount $account, string $url, int $size): array
    {
        $response = Http::withToken($this->client->accessToken($account))->timeout(30)->withoutRedirecting()
            ->withHeaders(['Content-Range' => "bytes */{$size}"])->withBody('', 'video/mp4')->put($url);
        if ($response->successful() && $response->json('id')) {
            return [null, $response->json()];
        }
        if ($response->status() === 308) {
            return [$this->rangeEnd($response->header('Range')), null];
        }

        return [null, null];
    }

    /** "bytes=0-524287" → 524288 (the next byte to send); no header → 0. */
    private function rangeEnd(string $range): int
    {
        return preg_match('/(\d+)-(\d+)/', $range, $m) ? (int) $m[2] + 1 : 0;
    }

    /** Throws QuotaExhausted for quota errors, else a RuntimeException with Google's reason. */
    private function guard(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $reason = (string) $response->json('error.errors.0.reason', '');
        if (in_array($reason, ['quotaExceeded', 'uploadLimitExceeded', 'dailyLimitExceeded', 'rateLimitExceeded'], true)) {
            throw new QuotaExhausted('YouTube quota: '.($response->json('error.message') ?: $reason));
        }
        $this->client->check($response, 'upload');
    }

    private function failed(FootballMatch $match, Throwable $e): string
    {
        $attempts = (int) $match->youtube_attempts + 1;
        $waits = (array) config('youtube.uploads.retry_minutes');
        $final = $attempts > count($waits);
        $match->forceFill([
            'youtube_status' => $final ? 'failed' : 'pending',
            'youtube_attempts' => $attempts,
            'youtube_next_at' => $final ? null : $this->clock()->addMinutes((int) $waits[$attempts - 1]),
            'youtube_error' => Str::limit($e->getMessage(), 1000),
        ])->save();
        $final
            ? Log::error('YouTube upload failed for good', ['match_id' => $match->id, 'attempts' => $attempts, 'error' => $e->getMessage()])
            : Log::warning('YouTube upload failed; retrying', ['match_id' => $match->id, 'attempt' => $attempts, 'error' => $e->getMessage()]);

        return $final ? 'failed' : 'retry';
    }

    /**
     * Videos uploaded through an API project that hasn't passed YouTube's audit are locked to
     * private whatever was asked: noted on the admin page.
     */
    private function restriction(array $video): ?string
    {
        $privacy = data_get($video, 'status.privacyStatus');

        return $privacy && $privacy !== config('youtube.uploads.privacy')
            ? "Uploaded, but YouTube set it to {$privacy} (videos from an unaudited API project stay private until the project passes YouTube's audit)."
            : null;
    }

    private function name($team): string
    {
        return trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) ($team?->name ?? '')));
    }

    /**
     * Now in the app's timezone (Africa/Lagos): dateTime columns keep the wall-clock of whatever Carbon
     * they get and are read back in the app's timezone, so the youtube_* times are written in it.
     */
    private function clock(): Carbon
    {
        return now(config('app.timezone'));
    }

    private function quotaDay(): string
    {
        return now(config('youtube.uploads.quota_timezone'))->toDateString();
    }

    private function countKey(): string
    {
        return 'youtube:uploads:'.$this->quotaDay();
    }

    private function exhaustedKey(): string
    {
        return 'youtube:quota-exhausted:'.$this->quotaDay();
    }
}
