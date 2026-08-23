<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GrowthPost extends Model
{
    protected $fillable = ['content_key', 'type', 'message', 'provider_post_id', 'status', 'last_error', 'published_at'];
    protected $casts = ['published_at' => 'datetime'];
}
