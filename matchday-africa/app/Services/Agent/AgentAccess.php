<?php

namespace App\Services\Agent;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Creates the agent login (role agent) and its API tokens; shared by agent:create and /admin/agent. */
class AgentAccess
{
    /**
     * Creates the agent user, or updates the one with this email. An existing admin or member is never
     * turned into an agent. Returns [user, password or null when an existing password was kept].
     */
    public function upsert(string $email, string $name = 'Matchday agent', ?string $password = null): array
    {
        $email = Str::lower(trim($email));
        $user = User::where('email', $email)->first();
        if ($user && !$user->isAgent()) {
            throw new \RuntimeException('That email belongs to an existing account that is not an agent; use another address.');
        }
        $plain = $password ?? ($user ? null : Str::password(24, symbols: false));
        $user ??= new User(['email' => $email]);
        $user->forceFill(['name' => $name, 'role' => User::ROLE_AGENT, 'is_admin' => false] + ($plain !== null ? ['password' => $plain] : []));
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();
        Log::info('Agent account saved', ['user_id' => $user->id]);

        return [$user, $plain];
    }

    /** A new long-lived token; with $revokeOthers the agent's older tokens stop working. Returns [token, plain]. */
    public function issueToken(User $user, ?int $by = null, bool $revokeOthers = true, string $name = 'agent'): array
    {
        if (!$user->isAgent()) {
            throw new \RuntimeException('Tokens are only issued to agent accounts.');
        }
        if ($revokeOthers) {
            ApiToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        [$token, $plain] = ApiToken::issue($user, $name, $by);
        Log::info('Agent API token issued', ['user_id' => $user->id, 'token_id' => $token->id, 'by' => $by]);

        return [$token, $plain];
    }
}
