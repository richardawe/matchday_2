<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Player extends Model
{
    protected $fillable = [
        'team_id',
        'football_data_id',
        'name',
        'position',
        'detailed_position',
        'shirt_number',
        'nationality',
        'nationality_code',
        'date_of_birth',
        'age',
        'photo_url',
        'is_active',
        'is_captain',
        'is_vice_captain',
        'height',
        'weight',
        'preferred_foot',
        'market_value',
        'contract_until',
        'last_api_update',
        'metadata'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'contract_until' => 'date',
        'is_active' => 'boolean',
        'is_captain' => 'boolean',
        'is_vice_captain' => 'boolean',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'market_value' => 'integer',
        'last_api_update' => 'datetime',
        'metadata' => 'json'
    ];

    /**
     * Get the team for this player
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Scope for active players
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by position
     */
    public function scopeByPosition($query, $position)
    {
        return $query->where('position', $position);
    }

    /**
     * Get formatted age
     */
    public function getFormattedAgeAttribute(): string
    {
        if ($this->age) {
            return $this->age . ' years';
        }
        
        if ($this->date_of_birth) {
            return Carbon::parse($this->date_of_birth)->age . ' years';
        }
        
        return 'N/A';
    }

    /**
     * Get formatted height
     */
    public function getFormattedHeightAttribute(): string
    {
        return $this->height ? $this->height . ' cm' : 'N/A';
    }

    /**
     * Get formatted weight
     */
    public function getFormattedWeightAttribute(): string
    {
        return $this->weight ? $this->weight . ' kg' : 'N/A';
    }

    /**
     * Get position emoji
     */
    public function getPositionEmojiAttribute(): string
    {
        return match($this->position) {
            'Goalkeeper', 'GK' => '🥅',
            'Defender', 'DEF', 'Defence' => '🛡️',
            'Midfielder', 'MID', 'Midfield' => '⚽',
            'Attacker', 'FWD', 'Offence', 'Forward' => '🎯',
            default => '⚽'
        };
    }

    /**
     * Get nationality flag emoji (basic implementation)
     */
    public function getNationalityFlagAttribute(): string
    {
        if (!$this->nationality_code) return '🏴';
        
        // Convert to regional indicator symbols (simplified)
        $flags = [
            'ENG' => '🏴󠁧󠁢󠁥󠁮󠁧󠁿',
            'SCO' => '🏴󠁧󠁢󠁳󠁣󠁴󠁿',
            'WAL' => '🏴󠁧󠁢󠁷󠁬󠁳󠁿',
            'NIR' => '🇬🇧',
            'FRA' => '🇫🇷',
            'GER' => '🇩🇪',
            'ESP' => '🇪🇸',
            'ITA' => '🇮🇹',
            'POR' => '🇵🇹',
            'NED' => '🇳🇱',
            'BEL' => '🇧🇪',
            'BRA' => '🇧🇷',
            'ARG' => '🇦🇷',
            'URU' => '🇺🇾',
            'COL' => '🇨🇴',
            'USA' => '🇺🇸',
            'CAN' => '🇨🇦',
            'MEX' => '🇲🇽',
            'JPN' => '🇯🇵',
            'KOR' => '🇰🇷',
            'AUS' => '🇦🇺',
            'NGA' => '🇳🇬',
            'GHA' => '🇬🇭',
            'SEN' => '🇸🇳',
            'CIV' => '🇨🇮',
            'MAR' => '🇲🇦',
            'EGY' => '🇪🇬',
            'TUN' => '🇹🇳',
            'ALG' => '🇩🇿'
        ];
        
        return $flags[$this->nationality_code] ?? '🏴';
    }

    /**
     * Get formatted market value
     */
    public function getFormattedMarketValueAttribute(): string
    {
        if (!$this->market_value) return 'N/A';
        
        if ($this->market_value >= 1000000) {
            return '€' . number_format($this->market_value / 1000000, 1) . 'M';
        } elseif ($this->market_value >= 1000) {
            return '€' . number_format($this->market_value / 1000) . 'K';
        }
        
        return '€' . number_format($this->market_value);
    }
}
