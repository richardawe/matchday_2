<?php

namespace App\Services;

use App\Models\User;
use App\Models\SocialAccount;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SocialAuthService
{
    /**
     * Handle Google OAuth callback
     */
    public function handleGoogleCallback(SocialiteUser $socialiteUser): User
    {
        return $this->findOrCreateUser($socialiteUser, 'google');
    }

    /**
     * Handle Twitter OAuth callback
     */
    public function handleTwitterCallback(SocialiteUser $socialiteUser): User
    {
        return $this->findOrCreateUser($socialiteUser, 'twitter');
    }

    /**
     * Find or create user from social provider
     */
    protected function findOrCreateUser(SocialiteUser $socialiteUser, string $provider): User
    {
        // Check if social account already exists
        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialiteUser->getId())
            ->first();

        if ($socialAccount) {
            // Update tokens if they exist
            if ($socialiteUser->token) {
                $socialAccount->update([
                    'provider_token' => $socialiteUser->token,
                    'provider_refresh_token' => $socialiteUser->refreshToken,
                    'provider_token_expires_at' => $socialiteUser->expiresIn ? now()->addSeconds($socialiteUser->expiresIn) : null,
                ]);
            }

            return $socialAccount->user;
        }

        // Check if user exists by email
        $user = User::where('email', $socialiteUser->getEmail())->first();

        if (!$user) {
            // Create new user
            $user = User::create([
                'name' => $socialiteUser->getName(),
                'email' => $socialiteUser->getEmail(),
                'password' => bcrypt(str()->random(32)), // Random password for social users
                'role' => 'user',
                'is_admin' => false,
            ]);
        }

        // Create social account
        $this->linkSocialAccount($user, $provider, $socialiteUser);

        return $user;
    }

    /**
     * Link social account to user
     */
    public function linkSocialAccount(User $user, string $provider, SocialiteUser $socialiteUser): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => $socialiteUser->getId(),
            'provider_token' => $socialiteUser->token,
            'provider_refresh_token' => $socialiteUser->refreshToken,
            'provider_token_expires_at' => $socialiteUser->expiresIn ? now()->addSeconds($socialiteUser->expiresIn) : null,
        ]);
    }

    /**
     * Unlink social account from user
     */
    public function unlinkSocialAccount(User $user, string $provider): bool
    {
        $socialAccount = $user->socialAccounts()->where('provider', $provider)->first();

        if (!$socialAccount) {
            return false;
        }

        return $socialAccount->delete();
    }

    /**
     * Get all social accounts for a user
     */
    public function getSocialAccounts(User $user)
    {
        return $user->socialAccounts;
    }

    /**
     * Check if user can unlink social account
     * (User must have at least one authentication method)
     */
    public function canUnlinkSocialAccount(User $user, string $provider): bool
    {
        $socialAccountsCount = $user->socialAccounts()->count();
        $hasPassword = !empty($user->password);

        // Can unlink if user has password or other social accounts
        return $hasPassword || $socialAccountsCount > 1;
    }

    /**
     * Get available providers for linking
     */
    public function getAvailableProviders(User $user): array
    {
        $allProviders = ['google', 'twitter'];
        $linkedProviders = $user->socialAccounts()->pluck('provider')->toArray();

        return array_diff($allProviders, $linkedProviders);
    }
}
