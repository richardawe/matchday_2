<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class EmailSubscriber extends Model
{
    protected $fillable = ['email', 'list', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'user_id', 'unsubscribe_token', 'consented_at', 'unsubscribed_at'];

    protected $casts = ['consented_at' => 'datetime', 'unsubscribed_at' => 'datetime'];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('email_subscribers');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('unsubscribed_at');
    }
}
