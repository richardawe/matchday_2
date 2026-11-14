<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class RadioBulletin extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'facts' => 'array', 'segments' => 'array', 'audio_cues' => 'array',
        'recorded_at' => 'datetime', 'published_at' => 'datetime', 'video_rendered_at' => 'datetime', 'video_seconds' => 'float',
    ];

    public static function available(): bool
    {
        return config('radio.enabled') && Schema::hasTable('radio_bulletins');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public static function latestPlayable(): ?self
    {
        if (! self::available()) {
            return null;
        }

        return self::published()->orderByDesc('slot')->limit(30)->get()->first(fn ($b) => $b->audioFile() !== null);
    }

    public function audioFile(): ?string
    {
        // Never accept arbitrary paths from stored metadata or route parameters.
        if (! preg_match('/^radio\/\d+\/[a-f0-9-]+\.mp3$/D', (string) $this->audio_path)) {
            return null;
        }
        $file = storage_path('app/'.$this->audio_path);

        return is_file($file) && filesize($file) === (int) $this->audio_bytes ? $file : null;
    }

    /**
     * Where a published edition plays from: a static copy in the web root, which the host serves
     * with byte ranges (iPhones need them to play and seek; PHP responses lose them). Drafts stay
     * private behind the PHP route.
     */
    public function audioUrl(): string
    {
        $file = $this->audioFile();
        if ($file && $this->status === 'published') {
            $public = public_path('generated/radio/'.$this->id.'/'.basename($file));
            try {
                if (! is_file($public) || filesize($public) !== filesize($file)) {
                    \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($public));
                    \Illuminate\Support\Facades\File::copy($file, $public.'.part');
                    rename($public.'.part', $public);
                }

                return asset('generated/radio/'.$this->id.'/'.basename($file));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Radio audio could not be published as a static file', ['bulletin' => $this->id, 'error' => $e->getMessage()]);
            }
        }

        return route('radio.audio', $this);
    }

    /** The vertical video's static file in the web root (served with byte ranges, like reels and slideshows). */
    public function publicVideoPath(): string
    {
        return public_path('generated/radio/'.$this->id.'/radio.mp4');
    }

    public function publicPosterPath(): string
    {
        return public_path('generated/radio/'.$this->id.'/poster.jpg');
    }

    /** True when the video rendered and its static file is still there. */
    public function videoReady(): bool
    {
        return $this->video_status === 'ready' && is_file($this->publicVideoPath())
            && (!$this->video_bytes || filesize($this->publicVideoPath()) === (int) $this->video_bytes);
    }

    public function videoUrl(): ?string
    {
        return $this->videoReady() ? asset('generated/radio/'.$this->id.'/radio.mp4') : null;
    }

    public function posterUrl(): ?string
    {
        return $this->videoReady() && is_file($this->publicPosterPath()) ? asset('generated/radio/'.$this->id.'/poster.jpg') : null;
    }

    /** "Saturday midday edition" from the Lagos slot. */
    public function editionLabel(): string
    {
        $slot = $this->slotTime();

        return $slot->format('l').' '.match ($slot->hour) { 6 => 'morning', 12 => 'midday', 18 => 'evening', default => $slot->format('H:i') }.' edition';
    }

    public function getDurationLabelAttribute(): string
    {
        return sprintf('%d:%02d', intdiv((int) $this->duration_seconds, 60), $this->duration_seconds % 60);
    }

    public function slotTime(): \Carbon\Carbon
    {
        return \Carbon\Carbon::createFromFormat('Y-m-d-H', substr($this->slot, 0, 13), 'Africa/Lagos')->startOfHour();
    }

    public function getIsDelayedAttribute(): bool
    {
        $slot = $this->slotTime();
        $next = $slot->copy()->setTime(match ($slot->hour) {
            6 => 12, 12 => 18, default => 6
        }, 0);
        if ($slot->hour === 18) {
            $next->addDay();
        }

        // The evening edition stays current overnight; no midnight edition is due.
        return now('Africa/Lagos')->gt($next->addMinutes(20));
    }
}
