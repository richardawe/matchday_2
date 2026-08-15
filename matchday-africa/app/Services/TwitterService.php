<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use GuzzleHttp\Client;
use Exception;
use App\Models\TwitterToken;

class TwitterService
{
    private string $clientId;
    private string $clientSecret;
    private string $accessToken;
    private string $refreshToken;
    private string $bearerToken;
    private string $username;

    public function __construct()
    {
        $this->clientId = config('services.twitter.client_id') ?? '';
        $this->clientSecret = config('services.twitter.client_secret') ?? '';
        $this->accessToken = config('services.twitter_api.access_token') ?? '';
        $this->refreshToken = config('services.twitter_api.refresh_token') ?? '';
        $this->bearerToken = config('services.twitter_api.bearer_token') ?? '';
        $this->username = config('services.twitter_api.username', 'matchdayafrica');

        if (!$this->clientId || !$this->accessToken) {
            Log::error('Twitter OAuth 2.0 credentials not configured');
            // Don't throw exception in constructor, handle it in methods instead
        }
    }

    /**
     * Check if Twitter API credentials are configured
     */
    private function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->accessToken);
    }

    /**
     * Generate PKCE code verifier and challenge
     */
    public function generatePKCE(): array
    {
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        
        return [
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge
        ];
    }

    /**
     * Get OAuth 2.0 authorization URL
     */
    public function getAuthorizationUrl(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Twitter OAuth 2.0 credentials not configured'
            ];
        }

        $pkce = $this->generatePKCE();
        $state = bin2hex(random_bytes(16));
        
        // Store PKCE data in session
        session([
            'twitter_pkce_code_verifier' => $pkce['code_verifier'],
            'twitter_pkce_state' => $state
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => route('twitter.oauth.callback'),
            'scope' => 'tweet.read tweet.write users.read',
            'state' => $state,
            'code_challenge' => $pkce['code_challenge'],
            'code_challenge_method' => 'S256'
        ];

        $authUrl = 'https://twitter.com/i/oauth2/authorize?' . http_build_query($params);

        return [
            'success' => true,
            'auth_url' => $authUrl,
            'state' => $state
        ];
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $code, string $state): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Twitter OAuth 2.0 credentials not configured'
            ];
        }

        $storedState = session('twitter_pkce_state');
        $codeVerifier = session('twitter_pkce_code_verifier');

        if ($state !== $storedState) {
            return [
                'success' => false,
                'error' => 'Invalid state parameter'
            ];
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post('https://api.twitter.com/2/oauth2/token', [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->clientId,
                    'redirect_uri' => route('twitter.oauth.callback'),
                    'code_verifier' => $codeVerifier
                ]);

            if ($response->successful()) {
                $data = $response->json();
                TwitterToken::updateOrCreate(['user_id' => null], [
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_at' => now()->addSeconds($data['expires_in'] ?? 7200),
                    'scope' => $data['scope'] ?? 'tweet.read tweet.write users.read',
                ]);
                
                // Store tokens (in production, store in database)
                session([
                    'twitter_access_token' => $data['access_token'],
                    'twitter_refresh_token' => $data['refresh_token'] ?? null,
                    'twitter_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 7200)
                ]);

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? null,
                    'expires_in' => $data['expires_in'] ?? 7200
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to exchange code for token: ' . $response->body()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Refresh access token
     */
    public function refreshAccessToken(): array
    {
        if (!$this->refreshToken) {
            return [
                'success' => false,
                'error' => 'No refresh token available'
            ];
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post('https://api.twitter.com/2/oauth2/token', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->refreshToken
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Update stored tokens
                session([
                    'twitter_access_token' => $data['access_token'],
                    'twitter_refresh_token' => $data['refresh_token'] ?? $this->refreshToken,
                    'twitter_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 7200)
                ]);

                return [
                    'success' => true,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $this->refreshToken,
                    'expires_in' => $data['expires_in'] ?? 7200
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to refresh token: ' . $response->body()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }


    /**
     * Post a tweet
     */
    public function postTweet(string $text): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Twitter API credentials not configured'
            ];
        }

        try {
            // Scheduled commands have no browser session, so prefer the persisted
            // account token and fall back to a long-lived environment token.
            $storedToken = TwitterToken::whereNull('user_id')->latest()->first();
            $accessToken = $storedToken?->access_token ?: $this->accessToken ?: session('twitter_access_token');
            if (!$accessToken) {
                return [
                    'success' => false,
                    'error' => 'No access token available. Please authorize the app first.'
                ];
            }

            // Use Twitter API v2 with OAuth 2.0 User Context for posting tweets
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post('https://api.twitter.com/2/tweets', [
                'text' => $text
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Tweet posted successfully', [
                    'tweet_id' => $data['data']['id'] ?? null,
                    'text' => $text
                ]);
                
                return [
                    'success' => true,
                    'tweet_id' => $data['data']['id'] ?? null,
                    'data' => $data
                ];
            } else {
                Log::error('Failed to post tweet', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'text' => $text
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Failed to post tweet: ' . $response->body()
                ];
            }
        } catch (Exception $e) {
            Log::error('Exception while posting tweet', [
                'error' => $e->getMessage(),
                'text' => $text
            ]);
            
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Post multiple tweets with delay between them
     */
    public function postMultipleTweets(array $tweets, int $delaySeconds = 30): array
    {
        $results = [];
        
        foreach ($tweets as $index => $tweet) {
            $result = $this->postTweet($tweet);
            $results[] = $result;
            
            // Add delay between tweets (except for the last one)
            if ($index < count($tweets) - 1) {
                sleep($delaySeconds);
            }
        }
        
        return $results;
    }

    /**
     * Get user information
     */
    public function getUserInfo(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Twitter API credentials not configured'
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->bearerToken,
            ])->get('https://api.twitter.com/2/users/by/username/' . $this->username);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to get user info: ' . $response->body()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test Twitter API connection
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Twitter API credentials not configured',
                'error' => 'Please configure Twitter API credentials in your .env file'
            ];
        }

        $userInfo = $this->getUserInfo();
        
        if ($userInfo['success']) {
            return [
                'success' => true,
                'message' => 'Twitter API connection successful',
                'username' => $this->username,
                'user_data' => $userInfo['data']
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Twitter API connection failed',
                'error' => $userInfo['error']
            ];
        }
    }

    /**
     * Format match tweet with hashtags and link
     */
    public function formatMatchTweet(array $match, string $matchUrl): string
    {
        $homeTeam = $match['home_team'];
        $awayTeam = $match['away_team'];
        $competition = $match['competition'] ?? 'Premier League';
        $kickoff = $match['kickoff'] ?? 'TBD';
        
        // Format kickoff time if it's a datetime
        if ($kickoff !== 'TBD' && strtotime($kickoff)) {
            $kickoff = date('H:i', strtotime($kickoff));
        }
        
        $hashtags = [
            '#EPL',
            '#PremierLeague', 
            '#Football',
            '#Soccer',
            '#MatchdayAfrica'
        ];
        
        $tweet = "⚽ {$homeTeam} vs {$awayTeam}\n";
        $tweet .= "🏆 {$competition}\n";
        $tweet .= "⏰ Kickoff: {$kickoff}\n\n";
        $tweet .= "🔗 View match details: {$matchUrl}\n\n";
        $tweet .= implode(' ', $hashtags);
        
        return $tweet;
    }

    /**
     * Format daily matches summary tweet
     */
    public function formatDailyMatchesTweet(array $matches, string $date): string
    {
        $matchCount = count($matches);
        $formattedDate = date('l, F j, Y', strtotime($date));
        
        $tweet = "📅 Today's Premier League Matches - {$formattedDate}\n\n";
        
        foreach ($matches as $match) {
            $homeTeam = $match['home_team'];
            $awayTeam = $match['away_team'];
            $kickoff = $match['kickoff'] ?? 'TBD';
            
            if ($kickoff !== 'TBD' && strtotime($kickoff)) {
                $kickoff = date('H:i', strtotime($kickoff));
            }
            
            $tweet .= "⚽ {$homeTeam} vs {$awayTeam} ({$kickoff})\n";
        }
        
        $tweet .= "\n🔗 View all matches: " . route('matches.index', ['date' => $date]) . "\n\n";
        $tweet .= "#EPL #PremierLeague #Football #MatchdayAfrica";
        
        return $tweet;
    }
}
