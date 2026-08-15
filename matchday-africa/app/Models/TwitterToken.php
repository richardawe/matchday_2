<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwitterToken extends Model
{
    protected $fillable = ['user_id', 'access_token', 'refresh_token', 'expires_at', 'scope'];
    protected $casts = ['expires_at' => 'datetime'];
}
