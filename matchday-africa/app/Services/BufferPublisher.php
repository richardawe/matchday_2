<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\MatchMoment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Hands the Griot's chapter cards and full-time reels to Buffer, which posts them to X,
 * Instagram and TikTok. Each subject goes to each channel once (social_posts).
 */
class BufferPublisher
{
    public function __construct(private MatchStoryService $stories, private ReelService $reels, private Griot $griot) {}

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
            ->whereIn('type', array_keys(array_diff_key(config('buffer.routes'), ['reel' => 1])))
            ->where('created_at', '>=', now()->subMinutes(config('buffer.max_age_minutes')))
            ->get();
        foreach ($chapters as $chapter) {
            [$headline, $line] = $this->griot->tell($chapter);
            $text = $this->caption($chapter->match, "{$headline} {$line}", ['moment' => $chapter->id]);
            foreach ($this->channelsFor($chapter->type) as $channel => $id) {
                $count($this->send("moment:{$chapter->id}", $channel, $id, $chapter->match, $text, image: $chapter->cardUrl('en', 'feed')));
            }
        }

        $finished = FootballMatch::with(['homeTeam', 'awayTeam'])->where('match_date', '>=', now('UTC')->subDays(1))->get()
            ->filter(fn ($match) => data_get($match->metadata, 'reel.status') === 'ready'
                && now()->diffInMinutes(data_get($match->metadata, 'reel.ready_at')) <= config('buffer.max_age_minutes'));
        foreach ($finished as $match) {
            $final = $this->stories->chapters($match)->firstWhere('type', 'full_time');
            $verdict = $final ? implode(' ', $this->griot->tell($final)) : '';
            $text = $this->caption($match, "The whole match in 20 seconds. {$verdict}");
            $video = route('matches.reel', ['match' => $match, 'v' => data_get($match->metadata, 'reel.ready_at')]);
            foreach ($this->channelsFor('reel') as $channel => $id) {
                $count($this->send("reel:{$match->id}", $channel, $id, $match, $text, video: $video));
            }
        }
        return $done;
    }

    /** Channel IDs on the Buffer account, for setting BUFFER_CHANNEL_* (organization → channels). */
    public function channels(): array
    {
        $orgs = $this->graphql('query { account { organizations { id name } } }');
        $channels = [];
        foreach (data_get($orgs, 'data.account.organizations', []) as $org) {
            $result = $this->graphql('query { channels(input: { organizationId: '.json_encode($org['id']).' }) { id name service } }');
            $channels = array_merge($channels, data_get($result, 'data.channels', []));
        }

        return $channels;
    }

    /** True when Buffer accepted it, false when it failed (retried next run), null when already sent. */
    private function send(string $subject, string $channel, string $channelId, FootballMatch $match, string $text, ?string $image = null, ?string $video = null): ?bool
    {
        if (DB::table('social_posts')->where(['subject' => $subject, 'channel' => $channel])->whereIn('status', ['sent', 'sending'])->exists()) {
            return null;
        }
        DB::table('social_posts')->updateOrInsert(['subject' => $subject, 'channel' => $channel],
            ['match_id' => $match->id, 'status' => 'sending', 'error' => null, 'updated_at' => now(), 'created_at' => now()]);

        // Strings are JSON-encoded, which is also a valid GraphQL string literal.
        $asset = $video
            ? '{ video: { url: '.json_encode($video, JSON_UNESCAPED_SLASHES).', metadata: { thumbnailOffset: 18500 } } }'
            : '{ image: { url: '.json_encode($image, JSON_UNESCAPED_SLASHES).' } }';
        $mutation = 'mutation { createPost(input: { text: '.json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).', channelId: '.json_encode($channelId)
            .', schedulingType: automatic, mode: '.config('buffer.mode').', assets: ['.$asset.'] }) '
            .'{ ... on PostActionSuccess { post { id } } ... on MutationError { message } } }';

        try {
            $result = $this->graphql($mutation);
            $postId = data_get($result, 'data.createPost.post.id');
            $error = $postId ? null : (data_get($result, 'data.createPost.message') ?? data_get($result, 'errors.0.message') ?? 'Unknown Buffer response');
        } catch (Throwable $e) {
            [$postId, $error] = [null, $e->getMessage()];
        }
        DB::table('social_posts')->where(['subject' => $subject, 'channel' => $channel])
            ->update(['status' => $postId ? 'sent' : 'failed', 'buffer_post_id' => $postId, 'error' => $error ? mb_substr($error, 0, 1000) : null, 'updated_at' => now()]);

        return (bool) $postId;
    }

    private function graphql(string $query): array
    {
        return Http::withToken(config('buffer.key'))->acceptJson()->timeout(20)
            ->post(config('buffer.endpoint'), ['query' => $query])->throw()->json() ?? [];
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
