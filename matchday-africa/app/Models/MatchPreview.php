<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchPreview extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'preview_content',
        'ai_model_used',
        'generated_at',
        'is_featured',
        'view_count',
        'generation_status'
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'is_featured' => 'boolean',
        'view_count' => 'integer'
    ];

    /**
     * Get the match that this preview belongs to
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    /**
     * Scope for featured previews
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope for active previews
     */
    public function scopeActive($query)
    {
        return $query->where('generation_status', 'completed');
    }

    /**
     * Increment view count
     */
    public function incrementViewCount()
    {
        $this->increment('view_count');
    }

    /**
     * Check if preview is recent (less than 6 hours old)
     */
    public function isRecent()
    {
        return $this->generated_at && $this->generated_at->diffInHours(now()) < 6;
    }

    /**
     * Get formatted generation time
     */
    public function getFormattedGeneratedTimeAttribute()
    {
        if ($this->generated_at) {
            return $this->generated_at->diffForHumans();
        }
        return 'Not generated';
    }
} 