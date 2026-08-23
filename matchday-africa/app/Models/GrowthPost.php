<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrowthPost extends Model
{
    protected $fillable = ['content_key', 'type', 'message', 'image_url', 'destination_url', 'provider_post_id', 'status', 'scheduled_at', 'last_error', 'published_at'];
    protected $casts = ['scheduled_at' => 'datetime', 'published_at' => 'datetime'];
}
