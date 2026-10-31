<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestPrediction extends Model
{
    protected $fillable = ['guest_token', 'match_id', 'home_score', 'away_score', 'utm_source', 'claimed_by', 'claimed_at'];

    protected $casts = ['home_score' => 'integer', 'away_score' => 'integer', 'claimed_at' => 'datetime'];

    public function match()
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
