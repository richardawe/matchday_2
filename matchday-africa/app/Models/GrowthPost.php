<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrowthPost extends Model
{
    protected $fillable = ['content_key', 'type', 'message', 'image_url', 'destination_url', 'provider_post_id', 'status', 'scheduled_at', 'last_error', 'published_at', 'expires_at', 'attempts', 'channel', 'video_url'];
    protected $casts = ['scheduled_at' => 'datetime', 'published_at' => 'datetime', 'expires_at' => 'datetime'];
}
