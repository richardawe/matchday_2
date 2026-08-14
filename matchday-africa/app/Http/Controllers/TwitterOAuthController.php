<?php

namespace App\Http\Controllers;

use App\Services\TwitterService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TwitterOAuthController extends Controller
{
    private TwitterService $twitterService;

    public function __construct(TwitterService $twitterService)
    {
        $this->twitterService = $twitterService;
    }

    /**
     * Initiate OAuth 2.0 authorization flow
     */
    public function authorize(): RedirectResponse
    {
        $result = $this->twitterService->getAuthorizationUrl();
        
        if ($result['success']) {
            return redirect($result['auth_url']);
        } else {
            return redirect()->back()->with('error', $result['error']);
        }
    }

    /**
     * Handle OAuth 2.0 callback
     */
    public function callback(Request $request): RedirectResponse
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');

        if ($error) {
            return redirect()->route('admin.twitter.index')
                ->with('error', 'Twitter authorization failed: ' . $error);
        }

        if (!$code || !$state) {
            return redirect()->route('admin.twitter.index')
                ->with('error', 'Missing authorization code or state parameter');
        }

        $result = $this->twitterService->exchangeCodeForToken($code, $state);
        
        if ($result['success']) {
            return redirect()->route('admin.twitter.index')
                ->with('success', 'Twitter authorization successful! You can now post tweets.');
        } else {
            return redirect()->route('admin.twitter.index')
                ->with('error', 'Failed to get access token: ' . $result['error']);
        }
    }

    /**
     * Revoke OAuth 2.0 access
     */
    public function revoke(Request $request): RedirectResponse
    {
        // Clear session data
        session()->forget([
            'twitter_access_token',
            'twitter_refresh_token',
            'twitter_token_expires_at',
            'twitter_pkce_code_verifier',
            'twitter_pkce_state'
        ]);

        return redirect()->route('admin.twitter.index')
            ->with('success', 'Twitter authorization revoked successfully.');
    }
}
