<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The connected YouTube channel. Tokens and the stream key are encrypted at rest. */
class YoutubeAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['refresh_token', 'access_token', 'stream_key'];

    protected $casts = [
        'refresh_token' => 'encrypted',
        'access_token' => 'encrypted',
        'stream_key' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
