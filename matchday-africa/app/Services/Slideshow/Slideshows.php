<?php

namespace App\Services\Slideshow;

use App\Models\Slideshow;
use App\Models\User;
use App\Services\YouTube\SlideshowUploads;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Renders waiting slideshows (highest priority first), publishes the mp4 and poster as static
 * files, and handles approval. An unapproved slideshow never reaches public pages, the social
 * queue API or YouTube.
 */
class Slideshows
{
    public function __construct(private SlideshowDeck $deck, private SlideshowRenderer $renderer, private SlideshowVideo $video) {}

    public static function enabled(): bool
    {
        return (bool) config('slideshow.enabled') && Slideshow::ready();
    }

    /** The current music track's credit record. */
    public static function music(): array
    {
        $key = (string) config('slideshow.music.track');
        $track = (array) config("slideshow.music.tracks.{$key}");

        return array_filter(['key' => $key, 'title' => $track['title'] ?? null, 'artist' => $track['artist'] ?? null,
            'licence' => $track['licence'] ?? null, 'licence_url' => $track['licence_url'] ?? null,
            'source_url' => ($track['source_url'] ?? '') ?: (self::uploaded()['source_url'] ?? null)], fn ($v) => $v !== null);
    }

    /** What an admin uploaded at /admin/slideshows: ['file' => relative to storage/app, 'source_url', 'uploaded_at', 'by'], or []. */
    public static function uploaded(): array
    {
        $key = (string) config('slideshow.music.track');
        $json = storage_path("app/music/{$key}.json");
        $data = is_file($json) ? json_decode((string) file_get_contents($json), true) : null;

        return is_array($data) ? $data : [];
    }

    /** Stores an uploaded track (replacing an earlier upload) and where it came from. */
    public static function storeMusic(\Illuminate\Http\UploadedFile $file, ?string $sourceUrl, ?int $userId): string
    {
        $key = (string) config('slideshow.music.track');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'mp3');
        $dir = storage_path('app/music');
        File::ensureDirectoryExists($dir);
        foreach (glob("{$dir}/{$key}.*") ?: [] as $old) {
            if (!str_ends_with($old, '.json')) {
                File::delete($old);
            }
        }
        $file->move($dir, "{$key}.{$ext}");
        file_put_contents("{$dir}/{$key}.json", json_encode(array_filter([
            'file' => "music/{$key}.{$ext}", 'source_url' => $sourceUrl ?: (self::uploaded()['source_url'] ?? null),
            'original_name' => $file->getClientOriginalName(), 'uploaded_at' => now()->toIso8601String(), 'by' => $userId,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Log::info('Slideshow music uploaded', ['track' => $key, 'user_id' => $userId]);

        return "{$dir}/{$key}.{$ext}";
    }

    /** 'Music: "Hot Amapiano Beats" by Alex Morgan (CC BY 4.0)' */
    public static function musicCredit(?array $music): string
    {
        if (!$music || empty($music['title'])) {
            return '';
        }

        return 'Music: "'.$music['title'].'"'.(!empty($music['artist']) ? ' by '.$music['artist'] : '')
            .(!empty($music['licence']) ? ' ('.$music['licence'].')' : '');
    }

    /** The track's file: SLIDESHOW_MUSIC_PATH / the config path, else what was uploaded at /admin/slideshows. */
    public static function musicFile(): string
    {
        $key = (string) config('slideshow.music.track');
        $configured = storage_path('app/'.ltrim((string) config("slideshow.music.tracks.{$key}.path"), '/'));
        $uploaded = self::uploaded()['file'] ?? null;

        return !is_file($configured) && $uploaded && is_file(storage_path('app/'.$uploaded)) ? storage_path('app/'.$uploaded) : $configured;
    }

    /** Waiting renders, best first: pending, or failed with attempts left. */
    public function waiting(): \Illuminate\Support\Collection
    {
        return Slideshow::with(['match.homeTeam', 'match.awayTeam', 'match.league', 'player.team'])
            ->where(fn ($q) => $q->where('status', 'pending')
                ->orWhere(fn ($q) => $q->where('status', 'failed')->where('attempts', '<', (int) config('slideshow.max_attempts', 3))
                    ->where('updated_at', '<=', now()->subMinutes(10))))
            ->orderByDesc('priority')->orderBy('id')->get();
    }

    /** Renders up to $limit waiting slideshows. Returns [ready, failed]. */
    public function renderWaiting(?int $limit = null): array
    {
        $limit ??= (int) config('slideshow.renders_per_run', 1);
        [$ready, $failed] = [0, 0];
        foreach ($this->waiting()->take($limit) as $show) {
            $this->render($show) ? $ready++ : $failed++;
        }

        return [$ready, $failed];
    }

    /** Builds the deck from current data and renders it. True when it is ready (awaiting approval). */
    public function render(Slideshow $show): bool
    {
        $claimed = Slideshow::whereKey($show->id)->where('status', '!=', 'rendering')
            ->update(['status' => 'rendering', 'attempts' => $show->attempts + 1, 'updated_at' => now()]);
        if (!$claimed) {
            return false;
        }
        $show->refresh();
        try {
            $deck = $this->deck->build($show);
            if (!$deck) {
                $show->forceFill(['status' => 'failed', 'attempts' => (int) config('slideshow.max_attempts', 3),
                    'error' => 'No longer qualifies: no in-scope player or the facts it needs are missing.'])->save();

                return false;
            }
            $dir = $show->workDir();
            File::deleteDirectory($dir);
            File::ensureDirectoryExists($dir);
            $slides = $this->renderer->render($deck['slides'], $dir);
            $track = (array) config('slideshow.music.tracks.'.config('slideshow.music.track'));
            $made = $this->video->make($slides, self::musicFile(), (float) ($track['start_seconds'] ?? 0), "{$dir}/slideshow.mp4");
            $this->poster($slides[0]['file'], "{$dir}/poster.jpg");

            $show->forceFill([
                'status' => 'ready', 'error' => null, 'rendered_at' => now(),
                'facts' => $deck['facts'] + ['zoom' => $made['zoom'], 'loudness' => $made['loudness']],
                'slides' => array_map(fn ($s) => array_diff_key($s, ['photo' => 1, 'flag' => 1, 'home_flag' => 1, 'away_flag' => 1]), $deck['slides']),
                'caption' => $deck['caption'], 'captions' => $deck['captions'], 'landing_path' => $deck['landing_path'],
                'player_id' => $deck['player_id'], 'photo' => $deck['photo'], 'music' => $deck['music'],
                'video_path' => "slideshows/{$show->id}/slideshow.mp4", 'poster_path' => "slideshows/{$show->id}/poster.jpg",
                'duration_seconds' => $made['seconds'],
                // A new render needs a new look from an editor.
                'approved' => false, 'approved_at' => null, 'approved_by' => null,
            ])->save();
            $this->publish($show);
            Log::info('Slideshow rendered', ['slideshow_id' => $show->id, 'type' => $show->type, 'seconds' => $made['seconds']]);

            return true;
        } catch (Throwable $e) {
            $show->forceFill(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000)])->save();
            Log::warning('Slideshow render failed', ['slideshow_id' => $show->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Copies the mp4 and poster into public/generated/slideshows/{id}/ (static files keep byte ranges). */
    public function publish(Slideshow $show): bool
    {
        $video = $show->videoFile();
        if (!$video) {
            return false;
        }
        try {
            foreach ([[$video, $show->publicVideoPath()], [storage_path('app/'.$show->poster_path), $show->publicPosterPath()]] as [$from, $to]) {
                if (!is_file($from)) {
                    continue;
                }
                if (!is_file($to) || filesize($to) !== filesize($from) || filemtime($to) < filemtime($from)) {
                    File::ensureDirectoryExists(dirname($to));
                    File::copy($from, "{$to}.part");
                    rename("{$to}.part", $to);
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('Slideshow could not be published as a static file', ['slideshow_id' => $show->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Approves a ready slideshow: it joins the social queue and is queued for YouTube. */
    public function approve(Slideshow $show, ?User $by = null): bool
    {
        if ($show->status !== 'ready' || !$show->videoFile()) {
            return false;
        }
        $show->forceFill(['approved' => true, 'approved_at' => now(), 'approved_by' => $by?->id])->save();
        $this->publish($show);
        Log::info('Slideshow approved', ['slideshow_id' => $show->id, 'user_id' => $by?->id]);
        try {
            app(SlideshowUploads::class)->approved($show);
        } catch (Throwable $e) {
            Log::warning('Could not queue the slideshow for YouTube', ['slideshow_id' => $show->id, 'error' => $e->getMessage()]);
        }

        return true;
    }

    public function unapprove(Slideshow $show, ?User $by = null): void
    {
        $show->forceFill(['approved' => false, 'approved_at' => null, 'approved_by' => null])->save();
        Log::info('Slideshow approval withdrawn', ['slideshow_id' => $show->id, 'user_id' => $by?->id]);
    }

    /** Back to the render queue from current data; it needs approving again afterwards. */
    public function regenerate(Slideshow $show): void
    {
        $show->forceFill(['status' => 'pending', 'attempts' => 0, 'error' => null, 'approved' => false, 'approved_at' => null, 'approved_by' => null])->save();
    }

    private function poster(string $png, string $jpg): void
    {
        $img = imagecreatefrompng($png);
        imagejpeg($img, $jpg, 86);
        imagedestroy($img);
    }
}
