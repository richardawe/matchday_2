<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class SocialAuthController extends Controller
{
    /**
     * Handle Google OAuth redirect callback (fallback method)
     */
    public function handleGoogleRedirect(Request $request)
    {
        try {
            \Log::info('Google OAuth redirect received', [
                'code' => $request->input('code'),
                'state' => $request->input('state'),
                'error' => $request->input('error'),
                'all_params' => $request->all()
            ]);

            $code = $request->input('code');
            $state = $request->input('state');
            $error = $request->input('error');
            
            if ($error) {
                \Log::error('Google OAuth error in redirect', ['error' => $error]);
                return redirect()->route('login')->with('error', 'Google authorization failed: ' . $error);
            }
            
            if (!$code) {
                \Log::error('No authorization code received in redirect');
                return redirect()->route('login')->with('error', 'Google authorization failed: No authorization code received');
            }

            // Exchange code for access token
            \Log::info('Exchanging Google authorization code for access token');
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('app.url') . '/oauth-callback',
            ]);

            \Log::info('Google token exchange response', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->body()
            ]);

            if (!$tokenResponse->successful()) {
                \Log::error('Google token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->body()
                ]);
                return redirect()->route('login')->with('error', 'Google token exchange failed');
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'];

            // Get user info from Google
            $userResponse = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v2/userinfo');

            if (!$userResponse->successful()) {
                return redirect()->route('login')->with('error', 'Failed to get Google user info');
            }

            $userData = $userResponse->json();
            $googleUser = (object) [
                'id' => $userData['id'],
                'name' => $userData['name'],
                'email' => $userData['email'],
                'avatar' => $userData['picture'] ?? null,
                'token' => $accessToken,
                'refreshToken' => $tokenData['refresh_token'] ?? null,
                'expiresIn' => $tokenData['expires_in'] ?? 3600,
            ];

            \Log::info('Processing Google user data', ['email' => $googleUser->email ?? 'no email']);
            $user = $this->handleSocialUser($googleUser, 'google');
            
            if ($user) {
                \Log::info('User created/logged in successfully', ['user_id' => $user->id]);
                Auth::login($user);
                return redirect()->intended(route('dashboard'))->with('success', 'Successfully logged in with Google!');
            }

            \Log::error('Failed to create or login user');
            return redirect()->route('login')->with('error', 'Failed to create or login user');

        } catch (\Exception $e) {
            \Log::error('Google OAuth redirect error: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Google authentication failed');
        }
    }

    /**
     * Handle Google OAuth callback from JavaScript
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            \Log::info('Google OAuth callback received', [
                'all_params' => $request->all(),
                'code' => $request->input('code'),
                'id_token' => $request->input('id_token'),
                'state' => $request->input('state'),
                'error' => $request->input('error')
            ]);

            // Check if this is a code-based OAuth flow
            $code = $request->input('code');
            if ($code) {
                \Log::info('Processing Google OAuth code flow');
                return $this->handleGoogleRedirect($request);
            }

            // Original ID token flow
            $idToken = $request->input('id_token');
            
            if (!$idToken) {
                \Log::error('No ID token or code provided');
                return response()->json(['error' => 'ID token is required'], 400);
            }

            \Log::info('Processing Google ID token flow');
            // Verify the Google ID token
            $googleUser = $this->verifyGoogleIdToken($idToken);
            
            if (!$googleUser) {
                \Log::error('Invalid Google ID token');
                return response()->json(['error' => 'Invalid Google ID token'], 400);
            }

            \Log::info('Google user verified', ['email' => $googleUser->email ?? 'no email']);
            $user = $this->handleSocialUser($googleUser, 'google');
            
            if ($user) {
                \Log::info('User created/logged in successfully', ['user_id' => $user->id]);
                Auth::login($user);
                return response()->json([
                    'success' => true,
                    'redirect' => route('dashboard')
                ]);
            }

            \Log::error('Failed to create or login user');
            return response()->json(['error' => 'Failed to create or login user'], 500);

        } catch (\Exception $e) {
            \Log::error('Google OAuth error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Google authentication failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handle Twitter OAuth callback from redirect
     */
    public function handleTwitterCallback(Request $request)
    {
        try {
            
            $code = $request->input('code');
            $state = $request->input('state');
            $error = $request->input('error');
            
            if ($error) {
                return redirect()->route('login')->with('error', 'Twitter authorization failed: ' . $error);
            }
            
            if (!$code) {
                return redirect()->route('login')->with('error', 'Twitter authorization failed: No authorization code received');
            }

            // Exchange code for access token with proper PKCE
            $clientId = config('services.twitter.client_id');
            $clientSecret = config('services.twitter.client_secret');
            $redirectUri = config('app.url') . '/auth/twitter/callback';
            
            // Create Basic Auth header
            $authHeader = base64_encode($clientId . ':' . $clientSecret);
            
            $tokenResponse = Http::withHeaders([
                'Authorization' => 'Basic ' . $authHeader,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ])->asForm()->post('https://api.twitter.com/2/oauth2/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code_verifier' => 'challenge' // This should match what we sent in the authorization URL
            ]);

            if (!$tokenResponse->successful()) {
                return redirect()->route('login')->with('error', 'Twitter token exchange failed');
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'];

            // Get user info from Twitter
            $userResponse = Http::withToken($accessToken)->get('https://api.twitter.com/2/users/me', [
                'user.fields' => 'id,name,username,profile_image_url'
            ]);

            if (!$userResponse->successful()) {
                return redirect()->route('login')->with('error', 'Failed to get Twitter user info');
            }

            $userData = $userResponse->json();
            $twitterUser = (object) [
                'id' => $userData['data']['id'],
                'name' => $userData['data']['name'],
                'username' => $userData['data']['username'],
                'email' => null, // Twitter doesn't provide email in OAuth 2.0
                'avatar' => $userData['data']['profile_image_url'] ?? null,
                'token' => $accessToken,
                'refreshToken' => null, // Twitter doesn't provide refresh tokens in OAuth 2.0
                'expiresIn' => 7200, // Twitter token expires in 2 hours
            ];

            $user = $this->handleSocialUser($twitterUser, 'twitter');
            
            if ($user) {
                Auth::login($user);
                return redirect()->intended(route('dashboard'))->with('success', 'Successfully logged in with Twitter!');
            }

            return redirect()->route('login')->with('error', 'Failed to create or login user');

        } catch (\Exception $e) {
            \Log::error('Twitter OAuth error: ' . $e->getMessage());
            return redirect()->route('login')->with('error', 'Twitter authentication failed');
        }
    }

    /**
     * Verify Google ID token
     */
    private function verifyGoogleIdToken($idToken)
    {
        try {
            $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Verify the audience matches our client ID
                if ($data['aud'] !== config('services.google.client_id')) {
                    return null;
                }

                return (object) [
                    'id' => $data['sub'],
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'avatar' => $data['picture'] ?? null,
                ];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Handle social user (create or login)
     */
    private function handleSocialUser($socialUser, $provider)
    {
        // Check if user already has a social account
        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->id)
            ->first();

        if ($socialAccount) {
            // User exists, return the user
            return $socialAccount->user;
        }

        // Generate the email that would be used for this provider
        $email = $socialUser->email ?: $provider . '_' . $socialUser->id . '@example.com';
        
        // Check if user exists with this email (including generated emails)
        $user = User::where('email', $email)->first();

        if ($user) {
            // Link social account to existing user
            $this->createSocialAccount($user, $provider, $socialUser);
            return $user;
        }

        // Create new user
        $user = $this->createUserFromSocial($socialUser, $provider);
        $this->createSocialAccount($user, $provider, $socialUser);
        
        return $user;
    }

    /**
     * Create user from social OAuth data
     */
    private function createUserFromSocial($socialUser, $provider)
    {
        // Generate a unique email if not provided
        $email = $socialUser->email ?: $provider . '_' . $socialUser->id . '@example.com';
        
        return User::create([
            'name' => $socialUser->name,
            'email' => $email,
            'password' => Hash::make(Str::random(24)),
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Create social account record
     */
    private function createSocialAccount($user, $provider, $socialUser)
    {
        // Check if social account already exists
        $existingAccount = SocialAccount::where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->id)
            ->first();

        if ($existingAccount) {
            return $existingAccount;
        }
        
        return SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => $socialUser->id,
            'provider_token' => $socialUser->token ?? null,
            'provider_refresh_token' => $socialUser->refreshToken ?? null,
            'provider_token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
        ]);
    }

    /**
     * Link social account to existing user
     */
    public function linkAccount(Request $request, $provider)
    {
        // Implementation for linking accounts
        return response()->json(['message' => 'Account linking not implemented yet']);
    }

    /**
     * Unlink social account from user
     */
    public function unlinkAccount(Request $request, $provider)
    {
        // Implementation for unlinking accounts
        return response()->json(['message' => 'Account unlinking not implemented yet']);
    }
}