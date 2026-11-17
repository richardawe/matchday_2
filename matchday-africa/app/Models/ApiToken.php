<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A long-lived bearer token for the agent API. Only its SHA-256 hash is stored. */
class ApiToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    public static function ready(): bool
    {
        return Schema::hasTable('api_tokens');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Creates a token for the user and returns [model, plain token]. The plain token is never stored. */
    public static function issue(User $user, string $name = 'agent', ?int $by = null, ?\DateTimeInterface $expires = null): array
    {
        $plain = 'mda_'.Str::random(48);
        $token = self::create(['user_id' => $user->id, 'name' => $name, 'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 10), 'created_by' => $by, 'expires_at' => $expires]);

        return [$token, $plain];
    }

    /** The live token for a plain bearer value, or null. */
    public static function findLive(?string $plain): ?self
    {
        if (!$plain || !str_starts_with($plain, 'mda_') || strlen($plain) > 200) {
            return null;
        }
        $token = self::with('user')->where('token_hash', hash('sha256', $plain))->first();
        if (!$token || $token->revoked_at || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }
}
