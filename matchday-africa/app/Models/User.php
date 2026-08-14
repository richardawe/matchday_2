<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_admin',
        'username','country_code','city','war_faction','bio','matchday_points','current_streak','best_streak','last_active_on','stripe_customer_id','stripe_subscription_id','subscription_status','premium_until',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'last_active_on' => 'date',
            'premium_until' => 'datetime',
        ];
    }

    /**
     * Get all prediction sets created by this user (admin)
     */
    public function predictionSets()
    {
        return $this->hasMany(PredictionSet::class, 'admin_id');
    }

    /**
     * Get all predictions made by this user
     */
    public function predictions()
    {
        return $this->hasMany(UserPrediction::class);
    }

    public function favorites()
    {
        return $this->hasMany(UserFavorite::class);
    }

    public function predictionGroups()
    {
        return $this->belongsToMany(PredictionGroup::class, 'prediction_group_members')->withTimestamps();
    }
    public function badges(){return $this->hasMany(UserBadge::class);}
    public function creatorProfile(){return $this->hasOne(CreatorProfile::class);}
    public function isPremium(): bool { return $this->subscription_status === 'active' && (!$this->premium_until || $this->premium_until->isFuture()); }

    /**
     * Get all predictions made by this user (alias for predictions)
     */
    public function userPredictions()
    {
        return $this->predictions();
    }

    /**
     * Get all leaderboard entries for this user
     */
    public function leaderboardEntries()
    {
        return $this->hasMany(PredictionLeaderboard::class);
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->is_admin || $this->role === 'admin';
    }

    /**
     * Check if user is a regular user
     */
    public function isUser(): bool
    {
        return !$this->isAdmin();
    }

    /**
     * Scope for admin users
     */
    public function scopeAdmins($query)
    {
        return $query->where('is_admin', true)->orWhere('role', 'admin');
    }

    /**
     * Scope for regular users
     */
    public function scopeUsers($query)
    {
        return $query->where('is_admin', false)->where('role', '!=', 'admin');
    }

    /**
     * Get all social accounts for this user
     */
    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Get all social shares made by this user
     */
    public function socialShares()
    {
        return $this->hasMany(SocialShare::class);
    }

    /**
     * Check if user has a social account for a specific provider
     */
    public function hasSocialAccount(string $provider): bool
    {
        return $this->socialAccounts()->where('provider', $provider)->exists();
    }

    /**
     * Get social account for a specific provider
     */
    public function getSocialAccount(string $provider)
    {
        return $this->socialAccounts()->where('provider', $provider)->first();
    }
}
