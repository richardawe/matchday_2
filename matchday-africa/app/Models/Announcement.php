<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message from Matchday Africa to users, sent from /admin/messages. */
class Announcement extends Model
{
    public const AUDIENCES = ['all' => 'Everyone with an account', 'alerts' => 'Users who set a match alert in the last 30 days', 'push' => 'Users with push turned on'];

    protected $fillable = ['title', 'body', 'url', 'audience', 'pushed', 'recipients', 'created_by'];

    protected $casts = ['pushed' => 'boolean', 'recipients' => 'integer'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
