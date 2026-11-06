<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A broadcast made on the connected channel: one per matchday, or a test. */
class YoutubeBroadcast extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function watchUrl(): string
    {
        return 'https://www.youtube.com/watch?v='.$this->broadcast_id;
    }

    public function studioUrl(): string
    {
        return 'https://studio.youtube.com/video/'.$this->broadcast_id.'/livestreaming';
    }
}
