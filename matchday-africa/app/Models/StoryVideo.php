<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A match-story video (kind match_story, 61-75 s after full time) or a radio video (kind
 * radio_video, as long as the edition's audio). The mp4 and cover are static files in
 * public/generated/stories/{id}/ so the host serves byte ranges (iPhones, Buffer, TikTok).
 */
class StoryVideo extends Model
{
    public const KINDS = ['match_story', 'radio_video'];

    protected $guarded = ['id'];

    protected $casts = [
        'captions' => 'array', 'photo' => 'array', 'music' => 'array', 'facts' => 'array', 'beats' => 'array',
        'captions_edited' => 'boolean', 'duration_seconds' => 'float', 'rendered_at' => 'datetime',
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('story_videos');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    public function bulletin(): BelongsTo
    {
        return $this->belongsTo(RadioBulletin::class, 'radio_bulletin_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** The hook on screen: the editor's line, else the one made from the data. */
    public function hookLine(): ?string
    {
        return $this->hook ?: $this->generated_hook;
    }

    public function banterLine(): ?string
    {
        return $this->banter ?: $this->generated_banter;
    }

    public function publicVideoPath(): string
    {
        return public_path("generated/stories/{$this->id}/story.mp4");
    }

    public function publicCoverPath(): string
    {
        return public_path("generated/stories/{$this->id}/cover.jpg");
    }

    public function videoReady(): bool
    {
        return $this->status === 'ready' && is_file($this->publicVideoPath())
            && (!$this->video_bytes || filesize($this->publicVideoPath()) === (int) $this->video_bytes);
    }

    /** The static mp4; the rendered time as a query keeps players from showing an older cut. */
    public function videoUrl(bool $versioned = false): ?string
    {
        if (!$this->videoReady()) {
            return null;
        }
        $url = asset("generated/stories/{$this->id}/story.mp4");

        return $versioned && $this->rendered_at ? $url.'?v='.$this->rendered_at->timestamp : $url;
    }

    public function coverUrl(): ?string
    {
        return $this->videoReady() && is_file($this->publicCoverPath()) ? asset("generated/stories/{$this->id}/cover.jpg") : null;
    }

    /** "story:12": the id the social queue, the admin page and the agent API share. */
    public function queueId(): string
    {
        return "story:{$this->id}";
    }

    public function title(): string
    {
        if ($this->kind === 'radio_video') {
            return $this->bulletin ? 'Matchday Radio, '.$this->bulletin->editionLabel() : 'Matchday Radio';
        }
        $m = $this->match;

        return $m ? \App\Support\ClubName::full($m->homeTeam).' '.($m->home_score ?? '').'-'.($m->away_score ?? '').' '.\App\Support\ClubName::full($m->awayTeam) : 'Match story';
    }
}
