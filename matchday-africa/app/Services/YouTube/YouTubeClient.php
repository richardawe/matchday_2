<?php

namespace App\Services\YouTube;

use App\Models\YoutubeAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Talks to Google's OAuth and the YouTube Data API v3 for the connected channel: sign-in, token
 * refresh, the channel's reusable stream key, and live broadcasts. Never logs tokens or the key.
 */
class YouTubeClient
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOKE = 'https://oauth2.googleapis.com/revoke';
    private const API = 'https://www.googleapis.com/youtube/v3/';
    public const STREAM_TITLE = 'Matchday Live';

    public static function configured(): bool
    {
        return (string) config('youtube.client_id') !== '' && (string) config('youtube.client_secret') !== '';
    }

    public static function ready(): bool
    {
        return Schema::hasTable('youtube_accounts') && Schema::hasTable('youtube_broadcasts');
    }

    public static function account(): ?YoutubeAccount
    {
        return self::ready() ? YoutubeAccount::latest('id')->first() : null;
    }

    /** Google's consent page: offline access and a forced consent screen, so a refresh token comes back. */
    public function authUrl(string $redirect, string $state): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => config('youtube.client_id'),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => implode(' ', config('youtube.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Finishes sign-in: swaps the code for tokens, reads the channel, and finds or makes the
     * reusable "Matchday Live" stream key. Replaces any earlier connection.
     */
    public function connect(string $code, string $redirect, ?int $userId): YoutubeAccount
    {
        $token = $this->check(Http::asForm()->timeout(20)->post(self::TOKEN, [
            'code' => $code, 'client_id' => config('youtube.client_id'), 'client_secret' => config('youtube.client_secret'),
            'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        ]), 'sign-in');
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google did not return a refresh token. Remove Matchday Africa from your Google account\'s third-party access and connect again.');
        }
        $access = (string) $token['access_token'];
        $channel = $this->check(Http::withToken($access)->timeout(20)->get(self::API.'channels', ['part' => 'snippet', 'mine' => 'true']), 'channel')['items'][0] ?? null;
        if (!$channel) {
            throw new RuntimeException('This Google account has no YouTube channel. Sign in with the account that owns the Matchday Africa channel.');
        }

        $account = new YoutubeAccount([
            'channel_id' => $channel['id'], 'channel_title' => data_get($channel, 'snippet.title'),
            'refresh_token' => $token['refresh_token'], 'access_token' => $access,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600) - 60), 'connected_by' => $userId,
        ]);
        $stream = $this->stream($access);
        $account->fill([
            'stream_id' => $stream['id'],
            'stream_key' => data_get($stream, 'cdn.ingestionInfo.streamName'),
            'ingest_url' => data_get($stream, 'cdn.ingestionInfo.ingestionAddress'),
        ]);
        YoutubeAccount::query()->delete();
        $account->save();

        return $account;
    }

    public function disconnect(YoutubeAccount $account): void
    {
        try {
            Http::asForm()->timeout(15)->post(self::REVOKE, ['token' => $account->refresh_token]);
        } catch (\Throwable) {
            // Revoking is best effort; the token is deleted here either way.
        }
        $account->delete();
    }

    /** Creates a scheduled broadcast that starts when the picture arrives and stops when it ends, bound to our stream. */
    public function createBroadcast(YoutubeAccount $account, string $title, string $description, \DateTimeInterface $start, \DateTimeInterface $end, string $privacy): array
    {
        $broadcast = $this->api($account, 'post', 'liveBroadcasts', ['part' => 'snippet,status,contentDetails'], [
            'snippet' => [
                'title' => mb_substr($title, 0, 100),
                'description' => mb_substr($description, 0, 5000),
                'scheduledStartTime' => $start->format(DATE_RFC3339),
                'scheduledEndTime' => $end->format(DATE_RFC3339),
            ],
            'status' => ['privacyStatus' => $privacy, 'selfDeclaredMadeForKids' => false],
            'contentDetails' => ['enableAutoStart' => true, 'enableAutoStop' => true, 'enableDvr' => true, 'latencyPreference' => 'low'],
        ]);
        $this->api($account, 'post', 'liveBroadcasts/bind', ['id' => $broadcast['id'], 'part' => 'id,contentDetails', 'streamId' => $account->stream_id]);

        return $broadcast;
    }

    /** created, ready, testing, live, complete, revoked … or null when YouTube no longer has it. */
    public function lifeCycle(YoutubeAccount $account, string $broadcastId): ?string
    {
        $items = $this->api($account, 'get', 'liveBroadcasts', ['part' => 'status', 'id' => $broadcastId])['items'] ?? [];

        return data_get($items, '0.status.lifeCycleStatus');
    }

    public function complete(YoutubeAccount $account, string $broadcastId): void
    {
        $this->api($account, 'post', 'liveBroadcasts/transition', ['broadcastStatus' => 'complete', 'id' => $broadcastId, 'part' => 'status']);
    }

    public function delete(YoutubeAccount $account, string $broadcastId): void
    {
        $this->api($account, 'delete', 'liveBroadcasts', ['id' => $broadcastId]);
    }

    /** The channel's stream health: active, inactive, ready … (for the admin page). */
    public function streamStatus(YoutubeAccount $account): ?string
    {
        if (!$account->stream_id) {
            return null;
        }
        $items = $this->api($account, 'get', 'liveStreams', ['part' => 'status', 'id' => $account->stream_id])['items'] ?? [];

        return data_get($items, '0.status.streamStatus');
    }

    /** Our reusable stream, made once and kept across matchdays. */
    private function stream(string $access): array
    {
        $existing = collect($this->check(Http::withToken($access)->timeout(20)->get(self::API.'liveStreams', ['part' => 'snippet,cdn,contentDetails', 'mine' => 'true', 'maxResults' => 50]), 'stream list')['items'] ?? [])
            ->first(fn ($s) => data_get($s, 'snippet.title') === self::STREAM_TITLE && data_get($s, 'contentDetails.isReusable'));
        if ($existing) {
            return $existing;
        }

        return $this->check(Http::withToken($access)->timeout(20)->post(self::API.'liveStreams?'.http_build_query(['part' => 'snippet,cdn,contentDetails']), [
            'snippet' => ['title' => self::STREAM_TITLE, 'description' => 'Matchday Africa live scores (matchday.africa/stream)'],
            'cdn' => ['ingestionType' => 'rtmp', 'resolution' => 'variable', 'frameRate' => 'variable'],
            'contentDetails' => ['isReusable' => true],
        ]), 'stream key');
    }

    private function api(YoutubeAccount $account, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $request = Http::withToken($this->accessToken($account))->timeout(25)->acceptJson();
        $url = self::API.$path.'?'.http_build_query($query);
        $response = match ($method) {
            'get' => $request->get($url),
            'delete' => $request->delete($url),
            default => $body === null ? $request->send('POST', $url) : $request->post($url, $body),
        };

        return $this->check($response, $path);
    }

    private function accessToken(YoutubeAccount $account): string
    {
        if ($account->access_token && $account->expires_at?->isFuture()) {
            return $account->access_token;
        }
        $token = $this->check(Http::asForm()->timeout(20)->post(self::TOKEN, [
            'client_id' => config('youtube.client_id'), 'client_secret' => config('youtube.client_secret'),
            'refresh_token' => $account->refresh_token, 'grant_type' => 'refresh_token',
        ]), 'token refresh');
        $account->update(['access_token' => $token['access_token'], 'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600) - 60)]);

        return $account->access_token;
    }

    /** The JSON body of a successful response, or an exception with Google's own reason (never a token). */
    private function check(Response $response, string $what): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }
        $reason = $response->json('error.message') ?? $response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status();

        throw new RuntimeException("YouTube {$what} failed: ".(is_string($reason) ? $reason : json_encode($reason)));
    }
}
