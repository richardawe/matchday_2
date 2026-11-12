<?php

namespace App\Services\YouTube;

use App\Models\YoutubeAccount;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What every Short upload shares, whatever the video (the 20-second reel, a colour slideshow):
 * the channel that may upload, the resumable upload itself and the channel's daily quota. An
 * upload costs 1,600 of 10,000 daily units, so reels and slideshows count against the same
 * youtube.uploads.daily_uploads per YouTube quota day (midnight Pacific).
 */
class ShortsUpload
{
    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public function __construct(private YouTubeClient $client) {}

    /** The connected channel when it may upload (and is YOUTUBE_CHANNEL_ID when that is set). */
    public function account(): ?YoutubeAccount
    {
        if (!YouTubeClient::configured() || !($account = YouTubeClient::account()) || !YouTubeClient::canUpload($account)) {
            return null;
        }
        $wanted = (string) config('youtube.channel_id');

        return $wanted === '' || $account->channel_id === $wanted ? $account : null;
    }

    /**
     * Resumable upload: starts (or resumes, with $url) the session and sends the file in chunks.
     * $started(['size' => …, 'url' => …]) is called once a new session exists, so it can be stored
     * and resumed after a crash. Returns the video resource.
     */
    public function send(YoutubeAccount $account, array $metadata, string $file, ?string $url, callable $started): array
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
                ->post(self::UPLOAD.'?'.http_build_query(['uploadType' => 'resumable', 'part' => 'snippet,status']), $metadata);
            $this->guard($response);
            $url = (string) $response->header('Location');
            if ($url === '') {
                throw new RuntimeException('YouTube did not return an upload session.');
            }
            Cache::put($this->countKey(), $this->usedToday() + 1, now()->addDays(2));
            $started(['size' => $size, 'url' => $url]);
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

    /** Uploads left today (YouTube's quota day, Pacific time), reels and slideshows together. */
    public function left(): int
    {
        if (Cache::get($this->exhaustedKey())) {
            return 0;
        }

        return max(0, (int) config('youtube.uploads.daily_uploads') - $this->usedToday());
    }

    public function usedToday(): int
    {
        return (int) Cache::get($this->countKey(), 0);
    }

    public function quotaReset(): Carbon
    {
        return now(config('youtube.uploads.quota_timezone'))->addDay()->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** YouTube said the quota is spent: nothing more goes up until the next quota day. */
    public function exhausted(): void
    {
        Cache::put($this->exhaustedKey(), true, $this->quotaReset());
    }

    /**
     * Videos uploaded through an API project that hasn't passed YouTube's audit are locked to
     * private whatever was asked: noted on the admin page.
     */
    public function restriction(array $video): ?string
    {
        $privacy = data_get($video, 'status.privacyStatus');

        return $privacy && $privacy !== config('youtube.uploads.privacy')
            ? "Uploaded, but YouTube set it to {$privacy} (videos from an unaudited API project stay private until the project passes YouTube's audit)."
            : null;
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
    private function guard(Response $response): void
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

    /** Today's YouTube quota day (Pacific), "2026-11-12". */
    public function quotaDay(): string
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
