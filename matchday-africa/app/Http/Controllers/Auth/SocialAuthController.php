<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SocialAuthService;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SocialAuthController extends Controller
{
    protected $socialAuthService;

    public function __construct(SocialAuthService $socialAuthService)
    {
        $this->socialAuthService = $socialAuthService;
    }

    /**
     * Redirect to Google OAuth
     */
    public function redirectToGoogle(): RedirectResponse
    {
        try {
            return Socialite::driver('google')->redirect();
        } catch (\Exception $e) {
            Log::error('Google OAuth redirect failed: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Unable to connect to Google. Please try again.');
        }
    }

    /**
     * Handle Google OAuth callback
     */
    public function handleGoogleCallback(): RedirectResponse
    {
        try {
            $socialiteUser = Socialite::driver('google')->user();
            $user = $this->socialAuthService->handleGoogleCallback($socialiteUser);
            
            Auth::login($user);
            
            return redirect()->intended(route('dashboard'));
        } catch (\Exception $e) {
            Log::error('Google OAuth callback failed: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Google authentication failed. Please try again.');
        }
    }

    /**
     * Redirect to Twitter OAuth
     */
    public function redirectToTwitter(): RedirectResponse
    {
        try {
            return Socialite::driver('twitter')->redirect();
        } catch (\Exception $e) {
            Log::error('Twitter OAuth redirect failed: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Unable to connect to Twitter. Please try again.');
        }
    }

    /**
     * Handle Twitter OAuth callback
     */
    public function handleTwitterCallback(): RedirectResponse
    {
        try {
            $socialiteUser = Socialite::driver('twitter')->user();
            $user = $this->socialAuthService->handleTwitterCallback($socialiteUser);
            
            Auth::login($user);
            
            return redirect()->intended(route('dashboard'));
        } catch (\Exception $e) {
            Log::error('Twitter OAuth callback failed: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Twitter authentication failed. Please try again.');
        }
    }

    /**
     * Link social account to current user
     */
    public function linkAccount(Request $request, string $provider): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Please log in to link social accounts.');
        }

        try {
            $socialiteUser = Socialite::driver($provider)->user();
            $this->socialAuthService->linkSocialAccount(Auth::user(), $provider, $socialiteUser);
            
            return redirect()->route('profile.edit')->with('success', ucfirst($provider) . ' account linked successfully!');
        } catch (\Exception $e) {
            Log::error("Failed to link {$provider} account: " . $e->getMessage());
            return redirect()->route('profile.edit')->with('error', 'Failed to link ' . ucfirst($provider) . ' account. Please try again.');
        }
    }

    /**
     * Unlink social account from current user
     */
    public function unlinkAccount(Request $request, string $provider): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Please log in to manage social accounts.');
        }

        $user = Auth::user();

        if (!$this->socialAuthService->canUnlinkSocialAccount($user, $provider)) {
            return redirect()->route('profile.edit')->with('error', 'Cannot unlink this account. You must have at least one way to log in.');
        }

        try {
            $this->socialAuthService->unlinkSocialAccount($user, $provider);
            
            return redirect()->route('profile.edit')->with('success', ucfirst($provider) . ' account unlinked successfully!');
        } catch (\Exception $e) {
            Log::error("Failed to unlink {$provider} account: " . $e->getMessage());
            return redirect()->route('profile.edit')->with('error', 'Failed to unlink ' . ucfirst($provider) . ' account. Please try again.');
        }
    }
}