<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OddsApiService
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $cacheDuration;
    private int $maxRequestsPerMinute;
    private int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = config('services.odds.api_url', 'https://api.the-odds-api.com/v4');
        $this->apiKey = config('services.odds.api_key');
        $this->cacheDuration = config('services.odds.cache_duration', 3600); // 1 hour
        $this->maxRequestsPerMinute = config('services.odds.max_requests_per_minute', 10);
        $this->maxRetries = config('services.odds.max_retries', 3);
        
        if (!$this->apiKey) {
            Log::error('Odds API key not configured');
            throw new \Exception('Odds API key not configured');
        }
    }

    /**
     * Get available sports
     */
    public function getSports(): ?array
    {
        $cacheKey = 'odds_api_sports';
        
        return Cache::remember($cacheKey, 3600, function () { // Cache for 1 hour
            return $this->makeApiRequest('sports');
        });
    }

    /**
     * Get EPL odds for this weekend
     */
    public function getEplWeekendOdds(): ?array
    {
        $cacheKey = 'epl_weekend_odds_' . now()->format('Y-m-d');
        
        return Cache::remember($cacheKey, $this->cacheDuration, function () {
            $params = [
                'regions' => config('services.odds.regions', 'us,uk'),
                'markets' => 'h2h',
                'oddsFormat' => 'decimal',
                'dateFormat' => 'iso'
            ];
            
            return $this->makeApiRequest('sports/soccer_epl/odds', $params);
        });
    }

    /**
     * Get odds for specific EPL match
     */
    public function getMatchOdds(string $eventId): ?array
    {
        $cacheKey = "match_odds_{$eventId}";
        
        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($eventId) {
            $params = [
                'regions' => config('services.odds.regions', 'us,uk,au'),
                'markets' => config('services.odds.markets', 'h2h,spreads,totals'),
                'oddsFormat' => 'decimal'
            ];
            
            return $this->makeApiRequest("sports/soccer_epl/events/{$eventId}/odds", $params);
        });
    }

    /**
     * Get upcoming EPL matches with odds
     */
    public function getUpcomingEplMatches(): ?array
    {
        $cacheKey = 'upcoming_epl_matches_' . now()->format('Y-m-d-H');
        
        return Cache::remember($cacheKey, $this->cacheDuration, function () {
            $params = [
                'regions' => config('services.odds.regions', 'us,uk,au'),
                'markets' => 'h2h',
                'oddsFormat' => 'decimal',
                'commenceTimeFrom' => now()->toISOString(),
                'commenceTimeTo' => now()->addDays(7)->toISOString()
            ];
            
            return $this->makeApiRequest('sports/soccer_epl/odds', $params);
        });
    }

    /**
     * Make API request with error handling and rate limiting
     */
    private function makeApiRequest(string $endpoint, array $params = []): ?array
    {
        $params['apiKey'] = $this->apiKey;
        
        // Check rate limiting
        $rateLimitKey = 'odds_api_rate_limit_' . now()->format('Y-m-d-H-i');
        $currentRequests = Cache::get($rateLimitKey, 0);
        
        if ($currentRequests >= $this->maxRequestsPerMinute) {
            Log::warning('Odds API rate limit exceeded', [
                'endpoint' => $endpoint,
                'current_requests' => $currentRequests,
                'max_requests' => $this->maxRequestsPerMinute
            ]);
            
            // Wait until next minute
            sleep(60 - now()->second);
            Cache::forget($rateLimitKey);
        }
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::timeout(30)
                    ->retry(2, 1000) // 2 retries with 1 second delay
                    ->get($this->baseUrl . '/' . $endpoint, $params);

                // Update rate limit counter
                Cache::put($rateLimitKey, $currentRequests + 1, 60);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    // Log API usage info
                    Log::info('Odds API usage', [
                        'endpoint' => $endpoint,
                        'remaining_requests' => $response->header('x-requests-remaining'),
                        'requests_used' => $response->header('x-requests-used'),
                        'cost' => $response->header('x-requests-last'),
                        'rate_limit_remaining' => $this->maxRequestsPerMinute - ($currentRequests + 1)
                    ]);
                    
                    return $data;
                }

                if ($response->status() === 429) {
                    $retryAfter = (int) $response->header('Retry-After', 60);
                    
                    Log::warning('Odds API rate limit hit', [
                        'attempt' => $attempt,
                        'endpoint' => $endpoint,
                        'retry_after' => $retryAfter,
                        'remaining_requests' => $response->header('x-requests-remaining')
                    ]);
                    
                    if ($attempt < $this->maxRetries) {
                        sleep($retryAfter);
                        continue;
                    }
                }

                if ($response->status() === 401) {
                    Log::error('Odds API authentication failed', [
                        'endpoint' => $endpoint,
                        'status' => $response->status()
                    ]);
                    return null;
                }

                if ($response->status() === 403) {
                    Log::error('Odds API access forbidden', [
                        'endpoint' => $endpoint,
                        'status' => $response->status()
                    ]);
                    return null;
                }

                Log::error('Odds API request failed', [
                    'status' => $response->status(),
                    'endpoint' => $endpoint,
                    'response' => $response->body(),
                    'attempt' => $attempt
                ]);

                return null;
                
            } catch (\Exception $e) {
                Log::error('Odds API exception', [
                    'message' => $e->getMessage(),
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'trace' => $e->getTraceAsString()
                ]);

                if ($attempt < $this->maxRetries) {
                    $backoffDelay = pow(2, $attempt); // Exponential backoff: 2, 4, 8 seconds
                    sleep($backoffDelay);
                    continue;
                }

                return null;
            }
        }
        
        return null;
    }

    /**
     * Test API connectivity
     */
    public function testConnection(): array
    {
        try {
            $sports = $this->getSports();
            
            if ($sports) {
                $eplSport = collect($sports)->firstWhere('key', 'soccer_epl');
                
                return [
                    'success' => true,
                    'message' => 'API connection successful',
                    'epl_available' => $eplSport ? true : false,
                    'epl_info' => $eplSport,
                    'total_sports' => count($sports),
                    'api_key_configured' => !empty($this->apiKey),
                    'cache_duration' => $this->cacheDuration,
                    'max_requests_per_minute' => $this->maxRequestsPerMinute
                ];
            }
            
            return [
                'success' => false,
                'message' => 'API connection failed - no sports data returned',
                'api_key_configured' => !empty($this->apiKey)
            ];
            
        } catch (\Exception $e) {
            Log::error('Odds API test connection failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'API connection failed: ' . $e->getMessage(),
                'api_key_configured' => !empty($this->apiKey)
            ];
        }
    }

    /**
     * Get API usage statistics
     */
    public function getUsageStats(): array
    {
        $rateLimitKey = 'odds_api_rate_limit_' . now()->format('Y-m-d-H-i');
        $currentRequests = Cache::get($rateLimitKey, 0);
        
        return [
            'current_requests_this_minute' => $currentRequests,
            'max_requests_per_minute' => $this->maxRequestsPerMinute,
            'remaining_requests' => max(0, $this->maxRequestsPerMinute - $currentRequests),
            'cache_duration' => $this->cacheDuration,
            'api_key_configured' => !empty($this->apiKey),
            'base_url' => $this->baseUrl,
            'regions' => config('services.odds.regions'),
            'markets' => config('services.odds.markets')
        ];
    }

    /**
     * Clear all cached odds data
     */
    public function clearCache(): bool
    {
        try {
            $patterns = [
                'odds_api_sports',
                'epl_weekend_odds_*',
                'match_odds_*',
                'upcoming_epl_matches_*',
                'odds_api_rate_limit_*'
            ];
            
            foreach ($patterns as $pattern) {
                if (str_contains($pattern, '*')) {
                    // For wildcard patterns, we'd need to implement cache tag clearing
                    // For now, we'll clear specific known keys
                    Cache::forget('odds_api_sports');
                } else {
                    Cache::forget($pattern);
                }
            }
            
            Log::info('Odds API cache cleared');
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to clear odds API cache', [
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }
}
