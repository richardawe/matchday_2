<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Player extends Model
{
    protected static function booted(): void
    {
        // Photo lookups cache squads per request; any change to a player clears them.
        static::saved(fn () => \App\Services\PlayerPhotos::flush());
        static::deleted(fn () => \App\Services\PlayerPhotos::flush());
    }

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

    /** Heritage records (only confirmed ones count anywhere public). */
    public function heritages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlayerHeritage::class);
    }

    /** ISO 3166 alpha-2 for a FIFA code ("NGA" → "NG"), or null. */
    public static function isoCode(?string $fifa): ?string
    {
        $code = strtoupper((string) $fifa);

        // ISO-3 spellings DiscoveryController::AFRICA uses where FIFA's differ (TAN, GAM, NIG).
        return self::FIFA_TO_ISO[$code] ?? (['TZA' => 'TZ', 'GMB' => 'GM', 'NER' => 'NE'][$code] ?? null);
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
     * FIFA three-letter codes (as PlayerService::getNationalityCode() writes them) to ISO 3166
     * alpha-2, for the flag emoji. Covers every code that service produces; FIFA and ISO differ
     * for many nations (GUI = Guinea GN, SLE = Sierra Leone SL, RSA = South Africa ZA ...).
     */
    private const FIFA_TO_ISO = [
        'ALB' => 'AL', 'ALG' => 'DZ', 'ANG' => 'AO', 'ARG' => 'AR', 'ARM' => 'AM', 'AUS' => 'AU', 'AUT' => 'AT',
        'AZE' => 'AZ', 'BDI' => 'BI', 'BEL' => 'BE', 'BEN' => 'BJ', 'BFA' => 'BF', 'BGR' => 'BG', 'BUL' => 'BG',
        'BHR' => 'BH', 'BIH' => 'BA', 'BOT' => 'BW', 'BRA' => 'BR', 'CAF' => 'CF', 'CAN' => 'CA', 'CGO' => 'CG',
        'CHA' => 'TD', 'CHI' => 'CL', 'CHN' => 'CN', 'CIV' => 'CI', 'CMR' => 'CM', 'COD' => 'CD', 'COL' => 'CO',
        'COM' => 'KM', 'CPV' => 'CV', 'CRO' => 'HR', 'CZE' => 'CZ', 'DEN' => 'DK', 'DJI' => 'DJ', 'ECU' => 'EC',
        'EGY' => 'EG', 'EQG' => 'GQ', 'ERI' => 'ER', 'ESP' => 'ES', 'ETH' => 'ET', 'FIN' => 'FI', 'FRA' => 'FR',
        'GAB' => 'GA', 'GAM' => 'GM', 'GEO' => 'GE', 'GER' => 'DE', 'GHA' => 'GH', 'GNB' => 'GW', 'GRE' => 'GR',
        'GUI' => 'GN', 'HRV' => 'HR', 'HUN' => 'HU', 'IDN' => 'ID', 'IND' => 'IN', 'IRL' => 'IE', 'IRN' => 'IR',
        'IRQ' => 'IQ', 'ISL' => 'IS', 'ISR' => 'IL', 'ITA' => 'IT', 'JAM' => 'JM', 'JOR' => 'JO', 'JPN' => 'JP',
        'KAZ' => 'KZ', 'KEN' => 'KE', 'KOR' => 'KR', 'KUW' => 'KW', 'LBN' => 'LB', 'LBR' => 'LR', 'LBY' => 'LY',
        'LES' => 'LS', 'MAD' => 'MG', 'MAR' => 'MA', 'MAS' => 'MY', 'MEX' => 'MX', 'MKD' => 'MK', 'MLI' => 'ML',
        'MNE' => 'ME', 'MOZ' => 'MZ', 'MRI' => 'MU', 'MTN' => 'MR', 'MWI' => 'MW', 'NAM' => 'NA', 'NED' => 'NL',
        'NGA' => 'NG', 'NIG' => 'NE', 'NOR' => 'NO', 'NZL' => 'NZ', 'OMA' => 'OM', 'PAR' => 'PY', 'PER' => 'PE',
        'PHI' => 'PH', 'POL' => 'PL', 'POR' => 'PT', 'QAT' => 'QA', 'ROU' => 'RO', 'RSA' => 'ZA', 'RUS' => 'RU',
        'RWA' => 'RW', 'SAU' => 'SA', 'SDN' => 'SD', 'SEN' => 'SN', 'SEY' => 'SC', 'SIN' => 'SG', 'SLE' => 'SL',
        'SOM' => 'SO', 'SRB' => 'RS', 'SSD' => 'SS', 'STP' => 'ST', 'SUI' => 'CH', 'SVK' => 'SK', 'SVN' => 'SI',
        'SWE' => 'SE', 'SWZ' => 'SZ', 'SYR' => 'SY', 'TAN' => 'TZ', 'THA' => 'TH', 'TOG' => 'TG', 'TUN' => 'TN',
        'TUR' => 'TR', 'UAE' => 'AE', 'UGA' => 'UG', 'UKR' => 'UA', 'URU' => 'UY', 'USA' => 'US', 'VIE' => 'VN',
        'YEM' => 'YE', 'ZAM' => 'ZM', 'ZIM' => 'ZW', 'NIR' => 'GB',
    ];

    /** Flag emoji for the player's nationality; an empty string when the code is unknown. */
    public function getNationalityFlagAttribute(): string
    {
        $code = strtoupper((string) $this->nationality_code);
        // England, Scotland and Wales have their own tag-sequence flags.
        $home = ['ENG' => 'gbeng', 'SCO' => 'gbsct', 'WAL' => 'gbwls'];
        if (isset($home[$code])) {
            return "\u{1F3F4}".implode('', array_map(fn ($c) => mb_chr(0xE0000 + ord($c)), str_split($home[$code])))."\u{E007F}";
        }
        $iso = self::FIFA_TO_ISO[$code] ?? (strlen($code) === 2 ? $code : null);
        if (! $iso || ! preg_match('/^[A-Z]{2}$/', $iso)) {
            return '';
        }

        return implode('', array_map(fn ($c) => mb_chr(0x1F1E6 + ord($c) - 65), str_split($iso)));
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
