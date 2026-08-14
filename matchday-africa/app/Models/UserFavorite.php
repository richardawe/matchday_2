<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UserFavorite extends Model
{
    protected $fillable = [
        'user_id',
        'favorable_type',
        'favorable_id',
        'notifications_enabled',
        'notification_types'
    ];

    protected $casts = [
        'user_id' => 'integer',
        'favorable_id' => 'integer',
        'notifications_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favorable(): MorphTo
    {
        return $this->morphTo();
    }
}
