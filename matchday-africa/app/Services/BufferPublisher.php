<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\GrowthPost;
use App\Models\MatchMoment;
use App\Support\BufferUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Hands posts to Buffer:
 *   - the Griot's live chapter cards to X and Instagram as they happen, when the channel's slot
 *     is free (each subject to each channel once, social_posts);
 *   - the planned timeline in growth_posts (GrowthPostGenerator), full-time reels included.
 * Every channel posts at most once per daily slot (config buffer.slots), live posts included.
 */
class BufferPublisher
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private Griot $griot, private BufferClient $buffer) {}

    public function configured(): bool
    {
        return (bool) config('buffer.key') && array_filter(config('buffer.channels'));
    }

    /** Sends whatever is new. Returns [sent, failed]. */
    public function publishPending(): array
    {
        $done = [0, 0];
        $count = function (?bool $sent) use (&$done) { if ($sent !== null) { $sent ? $done[0]++ : $done[1]++; } };

        $chapters = MatchMoment::with('match.homeTeam', 'match.awayTeam', 'match.league')
            ->whereIn('type', array_keys(config('buffer.routes')))
            ->where('created_at', '>=', now()->subMinutes(config('buffer.max_age_minutes')))
            // Live means the match itself is recent: chapters rebuilt for an old match are not news.
            ->whereHas('match', fn ($q) => $q->where('match_date', '>=', now('UTC')->subHours(4)))
            ->get();
        foreach ($chapters as $chapter) {
            [$headline, $line] = $this->griot->tell($chapter);
            $text = $this->caption($chapter->match, "{$headline} {$line}", ['moment' => $chapter->id]);
            foreach ($this->channelsFor($chapter->type) as $channel => $id) {
                $count($this->send("moment:{$chapter->id}", $channel, $id, $chapter->match, $text, image: $chapter->cardUrl('en', 'feed')));
            }
        }

        [$sent, $failed] = $this->publishTimeline();
        $done = [$done[0] + $sent, $done[1] + $failed];

        return $done;
    }

    /**
     * Sends the most valuable due planned post on each channel whose slot is free. Returns [sent, failed].
     */
    public function publishTimeline(): array
    {
        $done = [0, 0];
        if (!config('buffer.x.auto') || !Schema::hasColumn('growth_posts', 'channel')) {
            return $done;
        }

        GrowthPost::where('status', 'queued')->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'last_error' => 'Not sent before it went out of date (no free slot in time).']);
        // A run that died mid-send leaves its post claimed; hand it back.
        GrowthPost::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'queued']);

        foreach (array_filter(config('buffer.channels')) as $channel => $channelId) {
            if (!$this->slotOpen($channel) || !BufferUsage::allows(true)) {
                continue;
            }
            $priority = config('buffer.x.priority');
            $post = GrowthPost::where('channel', $channel)->where('status', 'queued')->where('scheduled_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get()->sortBy(fn ($p) => [$priority[$p->type] ?? 9, $p->scheduled_at->timestamp, $p->id])->first();
            if (!$post || !GrowthPost::whereKey($post->id)->where('status', 'queued')->update(['status' => 'sending'])) {
                continue;
            }
            // The claim above bypassed the model; without this, putting it back to queued is a no-op.
            $post->forceFill(['status' => 'sending'])->syncOriginal();

            $image = $post->image_url && $this->reachable($post->image_url, 'image/') ? $post->image_url : null;
            $video = $post->video_url && $this->reachable($post->video_url, 'video/') ? $post->video_url : null;
            $missing = ($post->video_url && !$video) ? $post->video_url : (($post->image_url && !$image && $channel !== 'x') ? $post->image_url : null);
            $result = $missing
                ? ['error' => "The media did not load ({$missing}); Buffer fetches it from there."]
                : $this->buffer->createPost($channelId, $channel, $post->message, image: $image, video: $video);
            $attempts = $post->attempts + 1;

            if ($result['ok'] ?? false) {
                $post->update(['status' => 'sent', 'provider_post_id' => $result['id'], 'published_at' => now(), 'attempts' => $attempts,
                    'last_error' => $post->image_url && !$image ? 'Sent without its image: the image URL did not load.' : null]);
                $done[0]++;
            } else {
                $post->update(['status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued', 'attempts' => $attempts,
                    'last_error' => mb_substr($result['error'] ?? 'Unknown Buffer response', 0, 1000)]);
                $done[1]++;
            }
        }

        return $done;
    }

    /**
     * Whether a channel may post now: one post per slot (config buffer.slots, Lagos times), so
     * three slots mean at most three posts a day, spread through it. Counts live posts too.
     */
    public function slotOpen(string $channel): bool
    {
        $now = now(config('buffer.x.timezone'));
        $start = collect(config('buffer.slots'))->map(fn ($time) => $now->copy()->setTimeFromTimeString($time))
            ->filter(fn ($slot) => $slot->lte($now))->sort()->last();
        if (!$start) {
            return false;
        }
        $since = $start->setTimezone(config('app.timezone'));

        return !GrowthPost::where(['channel' => $channel, 'status' => 'sent'])->where('published_at', '>=', $since)->exists()
            && !(Schema::hasTable('social_posts') && DB::table('social_posts')->where(['channel' => $channel, 'status' => 'sent'])->where('updated_at', '>=', $since)->exists());
    }

    /** Channels on the Buffer account, for setting BUFFER_CHANNEL_*. */
    public function channels(): array
    {
        $orgs = $this->buffer->listOrganizations();
        if (isset($orgs['error'])) {
            throw new \RuntimeException($orgs['error']);
        }
        $channels = [];
        foreach ($orgs['organizations'] as $org) {
            $result = $this->buffer->listChannels($org['id']);
            if (isset($result['error'])) {
                throw new \RuntimeException($result['error']);
            }
            $channels = array_merge($channels, $result['channels']);
        }

        return $channels;
    }

    /** True when Buffer accepted it, false when it failed (retried next run), null when already sent or held back. */
    private function send(string $subject, string $channel, string $channelId, FootballMatch $match, string $text, ?string $image = null, ?string $video = null): ?bool
    {
        if (DB::table('social_posts')->where(['subject' => $subject, 'channel' => $channel])->whereIn('status', ['sent', 'sending'])->exists()
            || !BufferUsage::allows(true) || !$this->slotOpen($channel)) {
            return null;
        }
        DB::table('social_posts')->updateOrInsert(['subject' => $subject, 'channel' => $channel],
            ['match_id' => $match->id, 'status' => 'sending', 'error' => null, 'updated_at' => now(), 'created_at' => now()]);

        $result = $this->buffer->createPost($channelId, $channel, $text, $image, $video, config('buffer.mode'));
        $postId = ($result['ok'] ?? false) ? $result['id'] : null;
        DB::table('social_posts')->where(['subject' => $subject, 'channel' => $channel])
            ->update(['status' => $postId ? 'sent' : 'failed', 'buffer_post_id' => $postId,
                'error' => $postId ? null : mb_substr($result['error'] ?? 'Unknown Buffer response', 0, 1000), 'updated_at' => now()]);

        return (bool) $postId;
    }

    /** Buffer fetches media itself; a URL that doesn't load fails at the platform later, so check it first. */
    private function reachable(string $url, string $type): bool
    {
        try {
            // A single byte is enough to prove the file is there (reels support ranges).
            $response = Http::timeout(30)->withHeaders(['Range' => 'bytes=0-0'])->get($url);

            return $response->successful() && str_starts_with((string) $response->header('Content-Type'), $type);
        } catch (Throwable) {
            return false;
        }
    }

    /** Configured channels for this kind of post: name => Buffer channel ID. */
    private function channelsFor(string $kind): array
    {
        return array_filter(array_intersect_key(config('buffer.channels'), array_flip(config("buffer.routes.{$kind}", []))));
    }

    private function caption(FootballMatch $match, string $story, array $query = []): string
    {
        $link = route('matches.show', ['match' => $match] + $query);

        return trim("{$story}\n\n{$link}\n".config('buffer.hashtags'));
    }
}
