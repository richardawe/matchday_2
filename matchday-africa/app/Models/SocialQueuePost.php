<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One social-queue item posted on one channel (see App\Services\SocialQueue). */
class SocialQueuePost extends Model
{
    protected $fillable = ['subject', 'channel', 'external_post_id', 'url', 'posted_at'];

    protected $casts = ['posted_at' => 'datetime'];
}
