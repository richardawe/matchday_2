<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatchChat extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'user_id', 
        'message',
        'gif_url',
        'gif_title',
        'is_gif',
        'mentioned_users'
    ];

    protected $casts = [
        'is_gif' => 'boolean',
        'mentioned_users' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the match that this chat belongs to
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }

    /**
     * Get the user who sent this chat message
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the display content for this chat message
     */
    public function getContentAttribute(): string
    {
        if ($this->is_gif && $this->gif_url) {
            return $this->gif_title ?? 'GIF';
        }
        
        return $this->message ?? '';
    }

    /**
     * Scope to get recent chats for a match
     */
    public function scopeRecent($query, int $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    /**
     * Scope to get chats for a specific match
     */
    public function scopeForMatch($query, int $matchId)
    {
        return $query->where('match_id', $matchId);
    }

    /**
     * Extract mentions from message text
     */
    public static function extractMentions(string $message): array
    {
        preg_match_all('/@(\w+)/', $message, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Process message and highlight mentions
     */
    public function getProcessedMessageAttribute(): string
    {
        if ($this->is_gif || !$this->message) {
            return $this->message ?? '';
        }

        $message = $this->message;
        
        // Replace @username with highlighted mentions
        if (!empty($this->mentioned_users)) {
            foreach ($this->mentioned_users as $username) {
                $message = preg_replace(
                    '/@' . preg_quote($username, '/') . '\b/',
                    '<span class="bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-1 rounded font-medium">@' . $username . '</span>',
                    $message
                );
            }
        }

        return $message;
    }

    /**
     * Get mentioned users as User models
     */
    public function mentionedUsers()
    {
        if (empty($this->mentioned_users)) {
            return collect();
        }

        return \App\Models\User::whereIn('name', $this->mentioned_users)->get();
    }
}
