<?php

namespace App\Services\Story;

use App\Models\FootballMatch;
use App\Models\Player;
use App\Models\RadioBulletin;
use App\Models\StoryVideo;
use App\Services\RadioVideo;
use App\Services\RadioVisuals;
use App\Services\Slideshow\CommonsPhotos;
use App\Services\Slideshow\SlideshowFacts;
use App\Services\Slideshow\SlideshowVideo;
use App\Services\SocialQueue;
use App\Support\AfricanScope;
use App\Support\ClubName;
use App\Support\SocialLink;
use App\Support\XText;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Match-story videos and radio videos: who needs one, rendering, publishing and captions.
 *
 *   request()       one row per (kind, subject, language): from the full-time scan, the Content studio or the agent API
 *   queueFinished() finished matches in config story.competitions, STORY_DELAY_MINUTES after full time,
 *                   and published radio editions over 60 s
 *   renderNext()    claims the oldest waiting job while fewer than config story.concurrency are rendering
 *
 * Frames are drawn by StoryFrames and piped into ffmpeg (SlideshowVideo::pipe); the result is
 * checked (length, 1080x1920) and copied to public/generated/stories/{id}/.
 */
class StoryVideos
{
    public const NEEDS = ['encoder libx264', 'encoder aac', 'demuxer image2pipe', 'decoder mjpeg'];

    public function __construct(private StoryPlanner $planner, private StoryFrames $frames, private SlideshowVideo $encoder,
        private RadioVideo $radio, private RadioVisuals $visuals, private CommonsPhotos $photos) {}

    public static function enabled(): bool
    {
        return (bool) config('story.enabled') && StoryVideo::ready();
    }

    /** What this ffmpeg lacks (empty when it can make story videos). */
    public function missing(): array
    {
        $have = $this->encoder->capabilities();
        if ($have === []) {
            return ['a working ffmpeg (tried: '.implode(', ', $this->encoder->candidates()).')'];
        }
        $needs = self::NEEDS;
        if ($music = self::musicFile()) {
            $needs[] = 'decoder mp3';
            $needs[] = 'filter loudnorm';
        } else {
            $needs[] = 'decoder pcm_s16le';
        }

        return array_values(array_diff($needs, $have));
    }

    /** Our own track (config story.music.paths), or null: stories are then silent and the studio says so. */
    public static function musicFile(): ?string
    {
        foreach ((array) config('story.music.paths') as $path) {
            $file = str_starts_with($path, '/') ? $path : base_path($path);
            if (is_file($file) && filesize($file) > 1000) {
                return $file;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ jobs

    /**
     * Creates or re-queues the job for a subject. Editor/agent hook and banter overrides are kept
     * (an empty string clears them back to the generated line).
     */
    public function request(string $kind, int $subjectId, string $lang = 'en', array $overrides = [], string $source = 'admin', ?int $by = null): StoryVideo
    {
        if (!in_array($kind, StoryVideo::KINDS, true)) {
            throw new RuntimeException("Unknown story kind {$kind}.");
        }
        $lang = in_array($lang, (array) config('story.languages', ['en']), true) ? $lang : 'en';
        $column = $kind === 'radio_video' ? 'radio_bulletin_id' : 'match_id';
        $story = StoryVideo::firstOrNew(['kind' => $kind, $column => $subjectId, 'lang' => $lang]);
        foreach (['hook', 'banter'] as $field) {
            if (array_key_exists($field, $overrides)) {
                $value = trim((string) $overrides[$field]);
                $story->{$field} = $value === '' ? null : Str::limit($value, $field === 'hook' ? 120 : 180, '');
            }
        }
        $story->fill(['status' => 'pending', 'attempts' => 0, 'error' => null, 'source' => $story->exists ? $story->source : $source,
            'requested_by' => $by ?? $story->requested_by]);
        $story->save();

        return $story;
    }

    /** Adds jobs for finished matches and published radio editions; re-queues a story once when its match data changed. */
    public function queueFinished(): int
    {
        $added = 0;
        $delay = (int) config('story.delay_minutes', 2);
        $matches = FootballMatch::with('league')
            ->whereIn('status', ['FINISHED', 'FT'])
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->where('match_date', '>=', now('UTC')->subHours((int) config('story.within_hours', 12) + 3)->format('Y-m-d H:i:s'))
            ->where('match_date', '<=', now('UTC')->format('Y-m-d H:i:s'))
            ->get()
            // Events settle for a couple of minutes after the whistle; a feed that keeps touching the row is not waited on forever.
            ->filter(fn (FootballMatch $m) => !$m->last_api_update || $m->last_api_update->lte(now()->subMinutes($delay))
                || SlideshowFacts::kickoff($m)->lte(now('UTC')->subMinutes(150)))
            ->filter(fn (FootballMatch $m) => $this->covered($m) && !$m->statusInferred());
        $existing = StoryVideo::where('kind', 'match_story')->whereIn('match_id', $matches->pluck('id'))->get()->groupBy('match_id');
        foreach ($matches as $match) {
            $rows = $existing->get($match->id, collect());
            if ($rows->isEmpty()) {
                $this->request('match_story', $match->id, 'en', [], 'auto');
                $added++;

                continue;
            }
            // Drawn again once when the score or the goals and red cards changed soon after.
            foreach ($rows as $story) {
                if ($story->status === 'ready' && $story->refreshes < 1 && $story->rendered_at
                    && $story->rendered_at->gt(now()->subMinutes((int) config('story.refresh_minutes', 45)))
                    && $story->fingerprint && $story->fingerprint !== StoryPlanner::fingerprint($match)) {
                    $story->update(['status' => 'pending', 'attempts' => 0, 'refreshes' => $story->refreshes + 1]);
                    $added++;
                }
            }
        }
        if (RadioVideo::columnsReady()) {
            $min = (int) config('radio.video.min_seconds', 60);
            $have = StoryVideo::where('kind', 'radio_video')->pluck('radio_bulletin_id')->all();
            RadioBulletin::published()->where('published_at', '>=', now()->subDays((int) config('story.radio_days', 3)))
                ->where('duration_seconds', '>', $min)->whereNotIn('id', $have ?: [0])->orderBy('published_at')->get()
                ->filter(fn ($b) => $b->audioFile())
                ->each(function ($b) use (&$added) {
                    $this->request('radio_video', $b->id, 'en', [], 'auto');
                    $added++;
                });
        }

        return $added;
    }

    /** Competitions whose finished matches get a story automatically. */
    public function covered(FootballMatch $match): bool
    {
        $codes = array_map('strtolower', (array) config('story.competitions'));

        return $match->league && (in_array(strtolower((string) $match->league->short_code), $codes, true)
            || in_array(strtolower((string) $match->league->espn_slug), $codes, true));
    }

    /** The next job to render, claimed, or null (nothing waiting or enough renders running). */
    public function claimNext(): ?StoryVideo
    {
        StoryVideo::where('status', 'rendering')->where('updated_at', '<', now()->subMinutes(30))
            ->update(['status' => 'failed', 'error' => 'The render stopped before finishing.']);
        if (StoryVideo::where('status', 'rendering')->count() >= (int) config('story.concurrency', 2)) {
            return null;
        }
        $max = (int) config('story.max_attempts', 3);
        $candidates = StoryVideo::where(fn ($q) => $q->where('status', 'pending')
            ->orWhere(fn ($q) => $q->where('status', 'failed')->where('attempts', '<', $max)->where('updated_at', '<=', now()->subMinutes(3))))
            // Match stories before radio videos, new requests before retries, oldest first.
            ->orderByRaw("case when kind = 'match_story' then 0 else 1 end")->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderBy('updated_at')->orderBy('id')->limit(5)->get();
        foreach ($candidates as $story) {
            $claimed = StoryVideo::whereKey($story->id)->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'rendering', 'attempts' => $story->attempts + 1, 'updated_at' => now()]);
            if ($claimed) {
                return $story->refresh();
            }
        }

        return null;
    }

    /** Renders one job. Records ready or failed on the row. */
    public function render(StoryVideo $story): bool
    {
        if ($story->status !== 'rendering') {
            $story->update(['status' => 'rendering', 'attempts' => $story->attempts + 1]);
        }
        $dir = storage_path("app/stories/{$story->id}");
        $tmp = "{$dir}/render-".Str::uuid().'.mp4';
        $silence = null;
        try {
            File::ensureDirectoryExists($dir);
            [$beats, $audio, $seconds, $record] = $story->kind === 'radio_video' ? $this->radioPlan($story) : $this->matchPlan($story);
            if ($audio === null) {
                $silence = "{$dir}/silence.wav";
                self::writeSilence($silence, $seconds);
            }
            $fps = (int) config('story.fps', 30);
            $cover = null;
            $started = microtime(true);
            $this->encoder->pipe($this->args($audio ?? $silence, $audio !== null && $story->kind === 'match_story', $seconds, $tmp),
                $this->frames->frames($beats, $fps, $cover), (int) config('story.timeout', 900));
            $length = SocialQueue::mp4Seconds($tmp);
            if ($length === null || abs($length - $seconds) > 1.0) {
                throw new RuntimeException('The video length ('.($length === null ? 'unreadable' : round($length, 1).' s').") does not match the plan ({$seconds} s).");
            }
            if ($story->kind === 'match_story' && ($length < (float) config('story.min_seconds', 61) - 0.05 || $length > (float) config('story.max_seconds', 75) + 0.5)) {
                throw new RuntimeException('The story is '.round($length, 1).' s; it must be 61-75 s.');
            }
            $public = $story->publicVideoPath();
            File::ensureDirectoryExists(dirname($public));
            File::copy($tmp, "{$public}.part");
            rename("{$public}.part", $public);
            if ($cover) {
                File::put($story->publicCoverPath().'.part', $cover);
                rename($story->publicCoverPath().'.part', $story->publicCoverPath());
            }
            $story->forceFill($record + [
                'status' => 'ready', 'error' => null, 'duration_seconds' => round($length, 2), 'video_bytes' => filesize($public),
                'rendered_at' => now(), 'beats' => self::withoutPaths($beats),
            ])->save();
            if (!$story->captions_edited) {
                $story->forceFill(['captions' => $this->captions($story->fresh(['match.homeTeam', 'match.awayTeam', 'match.league', 'bulletin']))])->save();
            }
            Log::info('Story video rendered', ['story' => $story->id, 'kind' => $story->kind, 'seconds' => round($length, 1),
                'render_seconds' => round(microtime(true) - $started, 1)]);

            return true;
        } catch (Throwable $e) {
            Log::warning('Story video failed', ['story' => $story->id, 'error' => $e->getMessage()]);
            $story->forceFill(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000)])->save();

            return false;
        } finally {
            File::delete($tmp);
            if ($silence) {
                File::delete($silence);
            }
        }
    }

    /** [beats, audio file or null, seconds, fields to record] for a match story. */
    private function matchPlan(StoryVideo $story): array
    {
        $match = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])->find($story->match_id) ?? throw new RuntimeException('The match no longer exists.');
        if (!SlideshowFacts::finished($match)) {
            throw new RuntimeException('The match has not finished yet.');
        }
        $plan = $this->planner->plan($match, $story->lang, $story->hook, $story->banter);
        $seconds = round(array_sum(array_column($plan['beats'], 'seconds')), 3);
        $music = self::musicFile();

        return [$plan['beats'], $music, $seconds, [
            'generated_hook' => $plan['hook'], 'generated_banter' => $plan['banter'], 'player_id' => $plan['player_id'],
            'photo' => $plan['photo'], 'facts' => $plan['facts'] + ['question' => $plan['question']], 'fingerprint' => $plan['fingerprint'],
            'music' => $music ? ['title' => config('story.music.title'), 'file' => basename($music), 'owner' => 'Matchday Africa'] : null,
        ]];
    }

    /** [beats, audio, seconds, record] for a radio video: one beat per caption scene over the episode audio. */
    private function radioPlan(StoryVideo $story): array
    {
        $bulletin = RadioBulletin::find($story->radio_bulletin_id) ?? throw new RuntimeException('The radio edition no longer exists.');
        if ($bulletin->status !== 'published') {
            throw new RuntimeException('The radio edition is not published.');
        }
        $audio = $bulletin->audioFile() ?? throw new RuntimeException('The published audio file is missing.');
        $seconds = $this->radio->audioSeconds($audio) ?? (float) $bulletin->duration_seconds;
        $min = (int) config('radio.video.min_seconds', 60);
        if ($seconds <= $min) {
            throw new RuntimeException("The audio is {$seconds} s; videos need more than {$min} s.");
        }
        [$player, $photo] = $this->leadPlayer($bulletin);
        $t = StoryText::for($story->lang);
        $beats = [];
        foreach ($this->radio->scenes($bulletin, $seconds) as $scene) {
            $beats[] = ['type' => 'radio', 'start' => $scene['start'], 'seconds' => $scene['end'] - $scene['start'],
                'caption' => $scene['caption'], 'title' => $scene['slide']['title'] ?? $bulletin->title, 'art' => !empty($scene['slide']['image']) ? public_path($scene['slide']['image']) : null,
                'edition' => $bulletin->editionLabel().' · '.$bulletin->duration_label,
                'photo' => $photo ? ['file' => $photo['file'], 'crop' => $photo['crop'] ?? null, 'credit' => $photo['credit'] ?? null] : null,
                'lang' => $story->lang, 'sides' => null];
        }
        // Frame timing comes from start + seconds; make the last beat end exactly on the audio.
        $seconds = round($seconds, 3);

        return [$beats, $audio, $seconds, [
            'player_id' => $player?->id, 'photo' => $photo ? array_diff_key($photo, ['file' => 1, 'path' => 1]) : null,
            'facts' => ['bulletin_id' => $bulletin->id, 'edition' => $bulletin->editionLabel(), 'title' => $bulletin->title,
                'headlines' => RadioVideo::headlines($bulletin, 5)->all(), 'lead_player' => $player?->name, 'listen' => $t->t('radio_listen')],
            'generated_hook' => Str::limit((string) $bulletin->title, 150, ''), 'music' => null,
        ]];
    }

    /**
     * The lead story's player: the featured in-scope player of the lead segment's match, else an
     * in-scope player named in the lead headline. Only a licensed Commons photo is used.
     */
    public function leadPlayer(RadioBulletin $bulletin): array
    {
        $facts = collect($bulletin->facts)->keyBy('key');
        $keys = collect($bulletin->segments)->flatMap(fn ($s) => (array) ($s['fact_keys'] ?? []))->values();
        foreach ($keys->take(3) as $key) {
            if (preg_match('/^match:(\d+)$/', (string) $key, $m) && ($match = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])->find($m[1]))) {
                [$featured, $photo] = $this->planner->featured($match);
                if ($featured) {
                    return [$featured['player'], $photo];
                }
            }
            $fact = $facts->get($key);
            $text = ' '.Str::lower(Str::ascii(data_get($fact, 'headline').' '.data_get($fact, 'text'))).' ';
            if (trim($text) === '') {
                continue;
            }
            $found = AfricanScope::scope(Player::with('team')->active())->get()->first(function (Player $p) use ($text) {
                $name = Str::lower(Str::ascii($p->name));

                return mb_strlen($name) > 5 && str_contains($text, ' '.$name.' ');
            });
            if ($found) {
                $photo = $this->photos->forPlayer($found);

                return [$found, $photo && !empty($photo['credit']) && is_file($photo['file'] ?? '') ? $photo : null];
            }
        }

        return [null, null];
    }

    /** ffmpeg: JPEG frames on stdin plus the audio; music is looped, faded and normalised. */
    public function args(string $audio, bool $music, float $seconds, string $out): array
    {
        $fps = (string) config('story.fps', 30);
        $args = [$this->encoder->ffmpeg(), '-hide_banner', '-y', '-f', 'image2pipe', '-framerate', $fps, '-c:v', 'mjpeg', '-i', '-'];
        if ($music) {
            array_push($args, '-stream_loop', '-1');
        }
        array_push($args, '-i', $audio, '-map', '0:v', '-map', '1:a');
        if ($music) {
            $fade = (float) config('story.music.fade_out', 2.0);
            $l = (array) config('story.music.loudness');
            $start = max(0, $seconds - $fade);
            $args[] = '-af';
            $args[] = "atrim=0:{$seconds},volume='if(lt(t,0.3),t/0.3,if(gt(t,{$start}),max(0,({$seconds}-t)/{$fade}),1))':eval=frame,"
                .'loudnorm=I='.($l['i'] ?? -14).':TP='.($l['tp'] ?? -2).':LRA='.($l['lra'] ?? 11).',aresample=48000';
        }
        array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', (string) config('story.crf', 26), '-pix_fmt', 'yuv420p', '-r', $fps,
            '-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-t', (string) $seconds, '-movflags', '+faststart', '-f', 'mp4', $out);

        return $args;
    }

    /** A mono 24 kHz silent WAV of $seconds (no lavfi needed). */
    public static function writeSilence(string $file, float $seconds): void
    {
        $rate = 24000;
        $samples = (int) ceil(($seconds + 1) * $rate);
        $data = $samples * 2;
        $h = fopen($file, 'wb');
        fwrite($h, 'RIFF'.pack('V', 36 + $data).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', $data));
        $chunk = str_repeat("\0", 65536);
        for ($left = $data; $left > 0; $left -= 65536) {
            fwrite($h, $left >= 65536 ? $chunk : substr($chunk, 0, $left));
        }
        fclose($h);
    }

    /** Beats as stored for the record and the studio: no server paths. */
    public static function withoutPaths(array $value): array
    {
        $root = base_path();
        foreach ($value as $key => $item) {
            if (in_array($key, ['sides'], true)) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = self::withoutPaths($item);
            } elseif (is_string($item) && str_starts_with($item, $root)) {
                $value[$key] = basename($item);
            }
        }

        return $value;
    }

    // ------------------------------------------------------------------ captions

    /** Per-channel captions with links tagged utm_campaign=story; X fits 280 weighted characters. */
    public function captions(StoryVideo $story): array
    {
        $t = StoryText::for($story->lang);
        if ($story->kind === 'radio_video') {
            $bulletin = $story->bulletin;
            $page = $bulletin ? route('radio.show', $bulletin) : url('/listen');
            $link = fn (string $c) => SocialLink::to($page, $c, 'story', "radio-{$story->radio_bulletin_id}");
            $headlines = collect(data_get($story->facts, 'headlines', []))->take(3)->join('. ');
            $body = trim('Matchday Radio, '.data_get($story->facts, 'edition', '').'. '.($headlines !== '' ? $headlines.'.' : ''));
            $credit = $story->photo['credit'] ?? null ? $t->t('cap_photo', ['credit' => $story->photo['credit']]) : '';
            $disclosure = (string) config('radio.disclosure');
            $tags = '#MatchdayRadio '.config('story.hashtags');
            $long = fn (string $c) => SocialQueue::plain(trim("{$body}\n\n{$t->t('radio_listen')}: {$link($c)}\n\n".($credit !== '' ? "{$credit}\n" : '')."{$disclosure}\n\n{$tags}"));

            return ['tiktok' => $long('tiktok'), 'instagram' => $long('instagram'), 'youtube' => $long('youtube'),
                'x' => SocialQueue::plain(XText::compose($body.' Written with AI, voiced with Kokoro.', $link('x'), '#MatchdayRadio'))];
        }

        $match = $story->match;
        $facts = (array) $story->facts;
        $page = route('matches.show', $match);
        $link = fn (string $c) => SocialLink::to($page, $c, 'story', "match-{$match->id}");
        $score = "{$match->home_score}-{$match->away_score}";
        $lines = [$story->hookLine(), $t->t('cap_full_time', ['home' => ClubName::full($match->homeTeam), 'away' => ClubName::full($match->awayTeam),
            'score' => $score, 'competition' => $match->league?->name ?: 'Football'])];
        $goals = collect($facts['goals'] ?? [])->map(fn ($g) => trim(($g['minute_label'] ?? '').' '.($g['player'] ?? '').(!empty($g['own_goal']) ? ' (og)' : (!empty($g['penalty']) ? ' (pen)' : ''))))->filter();
        if ($goals->isNotEmpty()) {
            $lines[] = $t->t('cap_scorers', ['list' => $goals->join(', ')]);
        }
        $reds = collect($facts['red_cards'] ?? [])->map(fn ($r) => trim(($r['minute_label'] ?? '').' '.($r['player'] ?? '')))->filter();
        if ($reds->isNotEmpty()) {
            $lines[] = $t->t('cap_red', ['list' => $reds->join(', ')]);
        }
        $question = (string) ($facts['question'] ?? '');
        $credits = array_values(array_filter([
            !empty($story->photo['credit']) ? $t->t('cap_photo', ['credit' => $story->photo['credit']]) : null,
            $story->music ? $t->t('cap_music', ['title' => $story->music['title'] ?? 'Matchday original beat']) : null,
        ]));
        $tags = trim(config('story.hashtags').' '.app(\App\Services\Slideshow\SlideshowDeck::class)->competitionTag($match));
        $body = SocialQueue::plain(implode("\n", array_filter($lines)));
        $long = fn (string $c) => SocialQueue::plain(trim("{$body}\n\n{$question}\n\n{$t->t('cap_watch')} {$link($c)}\n\n"
            .($credits ? implode("\n", $credits)."\n\n" : '').$tags));
        $short = SocialQueue::plain(implode(' ', array_filter([$story->hookLine(), "{$match->homeTeam?->name} {$score} {$match->awayTeam?->name}."])));
        $xTail = trim((!empty($story->photo['credit']) ? 'Photo: '.Str::limit($story->photo['credit'], 60)."\n" : '').'#MatchdayAfrica');

        return [
            'tiktok' => $long('tiktok'),
            'instagram' => $long('instagram'),
            'x' => SocialQueue::plain(XText::compose($short.' '.$question, $link('x'), $xTail)),
            'youtube' => $long('youtube'),
        ];
    }

    /** The social queue item for a ready story (SocialQueue::storyItems). */
    public static function queueItem(StoryVideo $story): ?array
    {
        $video = $story->videoUrl();
        if (!$video) {
            return null;
        }
        $captions = collect((array) $story->captions)->only(SocialQueue::CHANNELS)->map(fn ($c) => SocialQueue::plain((string) $c))->all();
        $match = $story->match;
        $page = $story->kind === 'radio_video' ? ($story->bulletin ? route('radio.show', $story->bulletin) : url('/listen')) : ($match ? route('matches.show', $match) : null);
        $content = $story->kind === 'radio_video' ? "radio-{$story->radio_bulletin_id}" : "match-{$story->match_id}";

        return [
            'id' => $story->queueId(),
            'type' => $story->kind,
            'status' => 'ready',
            'lang' => $story->lang,
            'title' => $story->title(),
            'hook' => $story->hookLine(),
            'banter' => $story->banterLine(),
            'duration_seconds' => $story->duration_seconds !== null ? round((float) $story->duration_seconds, 1) : SocialQueue::duration($story->publicVideoPath()),
            'reel_url' => SocialQueue::https($video),
            'video_url' => SocialQueue::https($video),
            'reel_duration_seconds' => $story->duration_seconds !== null ? round((float) $story->duration_seconds, 1) : null,
            'cover_url' => $story->coverUrl() ? SocialQueue::https($story->coverUrl()) : null,
            'image_url' => $story->coverUrl() ? SocialQueue::https($story->coverUrl()) : null,
            'youtube_url' => null,
            'caption' => $captions['instagram'] ?? '',
            'captions' => $captions,
            'hashtags' => trim((string) config('story.hashtags')),
            'photo_credit' => $story->photo['credit'] ?? null,
            'photo' => $story->photo ? collect($story->photo)->only(['title', 'source_url', 'licence', 'licence_url', 'artist', 'credit'])->all() : null,
            'music' => $story->music ? collect($story->music)->only(['title', 'owner'])->all() : null,
            'page_url' => $page ? SocialQueue::https($page) : null,
            'landing_urls' => $page ? collect(SocialLink::CHANNELS)->mapWithKeys(fn ($c) => [$c => SocialLink::to($page, $c, 'story', $content)])->all() : [],
            'created_at' => $story->rendered_at ?? $story->updated_at,
        ];
    }
}
