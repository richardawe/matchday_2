<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One publish or unpublish of an article (App\Services\Articles\ArticlePublisher). */
class ArticlePublishLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['blog_id', 'action', 'actor', 'user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
