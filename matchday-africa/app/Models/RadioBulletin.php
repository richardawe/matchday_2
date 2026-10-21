<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class RadioBulletin extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'facts' => 'array', 'segments' => 'array',
        'recorded_at' => 'datetime', 'published_at' => 'datetime',
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
