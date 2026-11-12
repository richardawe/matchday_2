<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\GrowthPost;
use App\Models\MatchMoment;
use App\Models\SocialQueuePost;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the posting bot may post, newest first, for the last config('social.queue_days') days:
 *   - every Premier League match with a ready reel ("reel:<match_id>"), with the reel's static
 *     mp4 on this site, its length, its YouTube Short and the full-time story card;
 *   - every other post the generator planned in growth_posts ("growth:<id>", the X row of each
 *     subject): previews, fixtures, results, Beat the Griot, banter and news;
 *   - every colour slideshow an editor has approved ("slideshow:<id>"), with its static mp4,
 *     per-platform captions, the landing page tagged per channel, and the music and photo credits.
 * Each channel's posted state comes from social_queue_posts (the bot or an admin marks it), else
 * from what the site sent itself earlier (growth_posts) or, for YouTube, the Shorts upload.
 * The admin page (/admin/social-queue) and the API (/api/social-queue) both read this.
 */
class SocialQueue
{
    public const CHANNELS = ['x', 'instagram', 'tiktok', 'youtube'];

    public const GROWTH_TYPES = ['preview', 'fixtures', 'results', 'griot', 'banter', 'news'];

    public function __construct(private ReelService $reels, private GrowthPostGenerator $generator) {}

    public static function ready(): bool
    {
        return Schema::hasTable('social_queue_posts');
    }

    /** Every item, newest first. */
    public function items(): Collection
    {
        $items = $this->reelItems()->concat($this->growthItems())->concat($this->slideshowItems())
            ->sortByDesc(fn ($item) => $item['created_at'])->values();
        $posted = self::ready()
            ? SocialQueuePost::whereIn('subject', $items->pluck('id'))->get()->groupBy('subject')
            : collect();

        return $items->map(fn ($item) => $this->finish($item, $posted->get($item['id'], collect())));
    }

    public function find(string $id): ?array
    {
        return $this->items()->firstWhere('id', $id);
    }

    /** Records (or updates) a channel's post. Returns the item, or null for an unknown id. */
    public function markPosted(string $id, string $channel, ?string $externalId = null, ?string $url = null): ?array
    {
        if (!$this->find($id)) {
            return null;
        }
        SocialQueuePost::updateOrCreate(['subject' => $id, 'channel' => $channel],
            ['external_post_id' => $externalId, 'url' => $url, 'posted_at' => now()]);

        return $this->find($id);
    }

    /** Clears a channel's posted record. Returns the item, or null for an unknown id. */
    public function unmark(string $id, string $channel): ?array
    {
        SocialQueuePost::where(['subject' => $id, 'channel' => $channel])->delete();

        return $this->find($id);
    }

    private function since(): Carbon
    {
        return now('UTC')->subDays((int) config('social.queue_days', 14));
    }

    private function reelItems(): Collection
    {
        $since = $this->since();

        return FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->where('match_date', '>=', $since->copy()->subDay()->format('Y-m-d H:i:s'))
            ->whereNotNull('metadata')
            ->whereHas('league', fn ($q) => $q->where('short_code', 'PL')->orWhere('espn_slug', 'eng.1'))
            ->get()
            ->filter(fn ($m) => data_get($m->metadata, 'reel.status') === 'ready' && data_get($m->metadata, 'reel.ready_at')
                && Carbon::parse(data_get($m->metadata, 'reel.ready_at'))->gte($since))
            ->map(function (FootballMatch $match) {
                $file = $this->reels->path($match);
                $reelUrl = $this->reelUrl($match);
                if (!$file || !$reelUrl) {
                    return null; // Marked ready but the file is gone: nothing to post.
                }
                $card = MatchMoment::where('match_id', $match->id)->where('type', 'full_time')->latest('id')->first();
                $caption = GrowthPost::where('content_key', "reel:{$match->id}")->value('message') ?? $this->reelCaption($match);

                return [
                    'id' => "reel:{$match->id}",
                    'type' => 'reel',
                    'match' => $this->matchInfo($match),
                    'reel_url' => $reelUrl,
                    'reel_duration_seconds' => self::duration($file, $match),
                    'youtube_url' => $this->youtubeUrl($match),
                    'image_url' => $card ? self::https($card->cardUrl('en', 'feed')) : null,
                    'caption' => $caption,
                    'created_at' => Carbon::parse(data_get($match->metadata, 'reel.ready_at')),
                    '_match' => $match,
                ];
            })
            ->filter()->values();
    }

    private function growthItems(): Collection
    {
        if (!Schema::hasTable('growth_posts')) {
            return collect();
        }
        $rows = GrowthPost::whereIn('type', self::GROWTH_TYPES)
            ->where('content_key', 'not like', '%@%')
            ->where('created_at', '>=', $this->since()->copy()->setTimezone(config('app.timezone')))
            ->get();
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereIn('id', $rows->map(fn ($p) => self::matchId($p->content_key))->filter()->unique())
            ->get()->keyBy('id');

        return $rows->map(function (GrowthPost $post) use ($matches) {
            $match = $matches->get(self::matchId($post->content_key));

            return [
                'id' => "growth:{$post->id}",
                'type' => $post->type,
                'match' => $match ? $this->matchInfo($match) : null,
                'reel_url' => null,
                'reel_duration_seconds' => null,
                'youtube_url' => null,
                'image_url' => self::https($post->image_url),
                'caption' => $post->message,
                'created_at' => $post->created_at,
                '_growth' => $post,
            ];
        });
    }

    /**
     * Approved, rendered slideshows. reel_url carries the video too, so a bot that posts reels posts
     * these the same way; captions holds the text for each platform (X within 280 weighted characters).
     */
    private function slideshowItems(): Collection
    {
        if (!\App\Models\Slideshow::ready()) {
            return collect();
        }
        $since = $this->since();

        return \App\Models\Slideshow::with(['match.homeTeam', 'match.awayTeam', 'match.league', 'player.team'])->published()
            ->where('approved_at', '>=', $since->copy()->setTimezone(config('app.timezone')))
            ->get()
            ->map(function (\App\Models\Slideshow $show) {
                $file = $show->videoFile();
                $video = $file && is_file($show->publicVideoPath()) ? self::https((string) $show->videoUrl()) : null;
                if (!$video) {
                    return null; // Approved but the file is gone: nothing to post.
                }
                $landing = $show->landing_path ? self::https($show->landing_path) : self::https($show->pageUrl());
                $captions = collect((array) $show->captions)->only(self::CHANNELS)->map(fn ($c) => self::plain((string) $c))->all();

                return [
                    'id' => "slideshow:{$show->id}",
                    'type' => 'slideshow',
                    'slideshow_type' => $show->type,
                    'match' => $show->match ? $this->matchInfo($show->match) : null,
                    'player' => $show->player ? ['id' => $show->player->id, 'name' => $show->player->name,
                        'label' => \App\Support\AfricanScope::label($show->player)] : null,
                    'reel_url' => $video,
                    'video_url' => $video,
                    'reel_duration_seconds' => $show->duration_seconds ?? self::duration($file),
                    'youtube_url' => $show->youtube_video_id ? "https://www.youtube.com/shorts/{$show->youtube_video_id}" : null,
                    'image_url' => $show->posterUrl() ? self::https($show->posterUrl()) : null,
                    'caption' => $show->caption,
                    'captions' => $captions,
                    'page_url' => self::https($show->pageUrl()),
                    'landing_url' => $landing,
                    'landing_urls' => collect(\App\Support\SocialLink::CHANNELS)->mapWithKeys(fn ($c) => [$c => \App\Support\SocialLink::to($landing, $c, 'slideshow', "{$show->type}-{$show->id}")])->all(),
                    'music_credit' => \App\Services\Slideshow\Slideshows::musicCredit($show->music),
                    'music' => $show->music,
                    'photo_credit' => data_get($show->photo, 'credit'),
                    'photo' => $show->photo ? collect($show->photo)->only(['title', 'source_url', 'licence', 'licence_url', 'artist', 'credit'])->all() : null,
                    'created_at' => $show->approved_at,
                    '_slideshow' => $show,
                ];
            })->filter()->values();
    }

    /** Adds the channels' posted state, cleans the caption and formats times; drops internals. */
    private function finish(array $item, Collection $marks): array
    {
        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $mark = $marks->firstWhere('channel', $channel);
            $channels[$channel] = $mark
                ? ['posted' => true, 'posted_at' => self::iso($mark->posted_at), 'external_post_id' => $mark->external_post_id, 'url' => $mark->url, 'source' => 'queue']
                : ($this->postedBySite($item, $channel) ?? ['posted' => false, 'posted_at' => null, 'external_post_id' => null, 'url' => null, 'source' => null]);
        }
        $created = $item['created_at'];

        return array_merge(array_diff_key($item, ['_match' => 1, '_growth' => 1, '_slideshow' => 1]), [
            'caption' => self::plain((string) $item['caption']),
            'created_at' => self::iso($created),
            'channels' => $channels,
        ]);
    }

    /** What the site posted itself before the switch (growth_posts), or the Short for YouTube. */
    private function postedBySite(array $item, string $channel): ?array
    {
        if ($channel === 'youtube' && isset($item['_slideshow'])) {
            $show = $item['_slideshow'];

            return $show->youtube_video_id ? ['posted' => true, 'posted_at' => self::iso($show->youtube_uploaded_at), 'external_post_id' => $show->youtube_video_id,
                'url' => $item['youtube_url'], 'source' => 'youtube_upload'] : null;
        }
        if (isset($item['_slideshow'])) {
            return null; // The site never posted slideshows itself.
        }
        if ($channel === 'youtube') {
            $match = $item['_match'] ?? null;
            if ($match && $match->youtube_video_id) {
                return ['posted' => true, 'posted_at' => self::iso($match->youtube_uploaded_at), 'external_post_id' => $match->youtube_video_id,
                    'url' => $item['youtube_url'], 'source' => 'youtube_upload'];
            }

            return null;
        }
        $key = isset($item['_growth']) ? $item['_growth']->content_key : $item['id'];
        $row = GrowthPost::where('content_key', $channel === 'x' ? $key : "{$key}@{$channel}")->whereIn('status', ['sent', 'published'])->first();

        return $row ? ['posted' => true, 'posted_at' => self::iso($row->published_at), 'external_post_id' => $row->provider_post_id, 'url' => null, 'source' => 'site'] : null;
    }

    private function matchInfo(FootballMatch $match): array
    {
        $kickoff = Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
        $finished = in_array($match->status, ['FINISHED', 'FT', 'AWARDED'], true) && $match->home_score !== null && $match->away_score !== null;

        return [
            'id' => $match->id,
            'home' => \App\Support\ClubName::full($match->homeTeam),
            'away' => \App\Support\ClubName::full($match->awayTeam),
            'competition' => $match->league?->name,
            'kickoff_utc' => $kickoff->toIso8601ZuluString(),
            'kickoff_uk' => $kickoff->copy()->setTimezone('Europe/London')->format('D j M Y, H:i T'),
            'score' => $finished ? "{$match->home_score}-{$match->away_score}" : null,
            'home_score' => $finished ? (int) $match->home_score : null,
            'away_score' => $finished ? (int) $match->away_score : null,
            'url' => self::https(route('matches.show', $match)),
        ];
    }

    /** The reel's static mp4 under public/generated/reels, without the cache-busting query (a permanent link). */
    private function reelUrl(FootballMatch $match): ?string
    {
        $url = $this->reels->url($match); // also makes sure the static copy exists
        if (!$url || !str_contains($url, "/generated/reels/{$match->id}/{$match->id}.mp4")) {
            return null; // the PHP fallback route: no byte ranges, so not for Buffer
        }

        return self::https(Str::before($url, '?'));
    }

    private function youtubeUrl(FootballMatch $match): ?string
    {
        return $match->youtube_video_id ? "https://www.youtube.com/shorts/{$match->youtube_video_id}" : null;
    }

    private function reelCaption(FootballMatch $match): string
    {
        try {
            return $this->generator->reelCaption($match);
        } catch (Throwable) {
            return '';
        }
    }

    /** "preview:12" or "banter:12" gives 12. */
    private static function matchId(string $key): ?int
    {
        return preg_match('/^(?:preview|banter):(\d+)$/', $key, $m) ? (int) $m[1] : null;
    }

    /** Captions without emoji or arrows; the rest of the generator's text as is. */
    public static function plain(string $text): string
    {
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);

        return trim(preg_replace('/[ \t]+$/m', '', $text));
    }

    /**
     * An absolute https URL on the public site (APP_URL): links made for this request's host (the
     * admin page, a CLI run) are moved onto it, so the bot and Buffer always get matchday.africa.
     * Links to other sites only get https.
     */
    public static function https(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        $base = rtrim((string) config('app.url'), '/');
        $own = array_filter([parse_url($base, PHP_URL_HOST), request()->getHost(), 'localhost']);
        if (!preg_match('#^https?://#i', $url)) {
            $url = $base.'/'.ltrim($url, '/');
        } elseif (in_array(parse_url($url, PHP_URL_HOST), $own, true)) {
            $url = $base.preg_replace('#^https?://[^/]+#i', '', $url);
        }

        return preg_replace('#^http://#i', 'https://', $url);
    }

    private static function iso(?CarbonInterface $at): ?string
    {
        return $at ? $at->copy()->utc()->toIso8601ZuluString() : null;
    }

    /** The reel's length in seconds, read from the mp4 header; v2 reels are 20 s when that can't be read. */
    public static function duration(string $file, ?FootballMatch $match = null): ?float
    {
        $seconds = self::mp4Seconds($file);
        if ($seconds !== null) {
            return round($seconds, 1);
        }
        $style = (string) data_get($match?->metadata, 'reel.style', '');

        return str_starts_with($style, 'v2') ? 20.0 : null;
    }

    /** Duration from the moov/mvhd box of an MP4 file, or null. */
    public static function mp4Seconds(string $file): ?float
    {
        $size = @filesize($file);
        $h = $size ? @fopen($file, 'rb') : false;
        if (!$h) {
            return null;
        }
        try {
            $find = function (int $start, int $end, string $type) use ($h): ?array {
                for ($pos = $start; $pos + 8 <= $end;) {
                    fseek($h, $pos);
                    $head = fread($h, 8);
                    if (strlen($head) < 8) {
                        return null;
                    }
                    $len = unpack('N', $head)[1];
                    $headLen = 8;
                    if ($len === 1) {
                        $len = unpack('J', fread($h, 8))[1];
                        $headLen = 16;
                    } elseif ($len === 0) {
                        $len = $end - $pos;
                    }
                    if ($len < $headLen) {
                        return null;
                    }
                    if (substr($head, 4, 4) === $type) {
                        return [$pos + $headLen, min($end, $pos + $len)];
                    }
                    $pos += $len;
                }

                return null;
            };
            $moov = $find(0, $size, 'moov');
            $mvhd = $moov ? $find($moov[0], $moov[1], 'mvhd') : null;
            if (!$mvhd) {
                return null;
            }
            fseek($h, $mvhd[0]);
            $version = ord(fread($h, 1));
            fread($h, 3);
            fread($h, $version === 1 ? 16 : 8);
            $scale = unpack('N', fread($h, 4))[1];
            $duration = $version === 1 ? unpack('J', fread($h, 8))[1] : unpack('N', fread($h, 4))[1];

            return $scale > 0 ? $duration / $scale : null;
        } catch (Throwable) {
            return null;
        } finally {
            fclose($h);
        }
    }
}
