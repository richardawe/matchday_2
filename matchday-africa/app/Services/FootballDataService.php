<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FootballDataService
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $cacheDuration;
    private int $maxRequestsPerMinute;
    private int $maxRetries;
    private string $rateLimitCacheKey = 'football_data_rate_limit';

    public function __construct()
    {
        $this->baseUrl = config('services.football_data.url', 'https://api.football-data.org/v4');
        $this->apiKey = config('services.football_data.key') ?: '';
        $this->cacheDuration = config('services.football_data.cache_duration', 300); // 5 minutes default
        $this->maxRequestsPerMinute = config('services.football_data.max_requests_per_minute', 10);
        $this->maxRetries = 3;
    }

    public function get(string $endpoint, array $params = []): ?array
    {
        // Check if we should use cache based on endpoint type
        $cacheDuration = $this->getCacheDurationForEndpoint($endpoint, $params);
        $cacheKey = $this->generateCacheKey($endpoint, $params);
        
        // For real-time data, reduce cache or skip cache entirely
        if ($cacheDuration === 0) {
            return $this->makeApiRequest($endpoint, $params);
        }
        
        return Cache::remember($cacheKey, $cacheDuration, function () use ($endpoint, $params) {
            return $this->makeApiRequest($endpoint, $params);
        });
    }

    /**
     * Make API request with enhanced rate limiting and retry logic
     */
    private function makeApiRequest(string $endpoint, array $params): ?array
    {
        // Check rate limit before making request
        if (!$this->checkRateLimit()) {
            Log::warning('Rate limit exceeded, delaying request', ['endpoint' => $endpoint]);
            $this->waitForRateLimit();
        }

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                // Track this request
                $this->trackRequest();
                
                $response = Http::timeout(30)->withHeaders([
                    'X-Auth-Token' => $this->apiKey,
                    'Accept' => 'application/json',
                    'User-Agent' => 'MatchdayAfrica/1.0'
                ])->get($this->baseUrl . '/' . $endpoint, $params);

                // Log API usage info
                $this->logApiUsage($response, $endpoint);

                if ($response->successful()) {
                    return $response->json();
                }

                // Handle rate limiting (429) specifically
                if ($response->status() === 429) {
                    $retryAfter = (int) ($response->header('Retry-After') ?? 60);
                    
                    Log::warning('Football Data API rate limit hit', [
                        'attempt' => $attempt,
                        'endpoint' => $endpoint,
                        'retry_after' => $retryAfter,
                        'requests_remaining' => $response->header('X-Requests-Available-Minute')
                    ]);
                    
                    // Update our rate limit tracker
                    $this->updateRateLimitInfo($response);
                    
                    if ($attempt < $this->maxRetries) {
                        $waitTime = min($retryAfter, 120); // Wait max 2 minutes
                        sleep($waitTime);
                        continue;
                    }
                }

                // Handle other HTTP errors
                if ($response->status() >= 500) {
                    Log::warning('Football Data API server error', [
                        'status' => $response->status(),
                        'endpoint' => $endpoint,
                        'attempt' => $attempt
                    ]);
                    
                    if ($attempt < $this->maxRetries) {
                        sleep(pow(2, $attempt)); // Exponential backoff: 2, 4, 8 seconds
                        continue;
                    }
                }

                Log::warning('Football Data API request failed', [
                    'status' => $response->status(),
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempt,
                    'response_body' => $response->body()
                ]);

                return null;
                
            } catch (\Exception $e) {
                Log::error('Football Data API exception', [
                    'message' => $e->getMessage(),
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'attempt' => $attempt,
                    'trace' => $e->getTraceAsString()
                ]);

                if ($attempt < $this->maxRetries) {
                    sleep(pow(2, $attempt)); // Exponential backoff
                    continue;
                }

                return null;
            }
        }
        
        return null;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }

    /**
     * Get all competitions/leagues
     */
    public function getCompetitions(): ?array
    {
        return $this->get('competitions');
    }

    /**
     * Get standings for a specific competition
     */
    public function getCompetitionStandings(string $competitionCode): ?array
    {
        return $this->get("competitions/{$competitionCode}/standings");
    }

    /**
     * Get matches for a specific competition
     */
    public function getCompetitionMatches(string $competitionCode, array $params = []): ?array
    {
        return $this->get("competitions/{$competitionCode}/matches", $params);
    }

    /**
     * Get teams for a specific competition
     */
    public function getCompetitionTeams(string $competitionCode): ?array
    {
        return $this->get("competitions/{$competitionCode}/teams");
    }

    /**
     * Get team details including squad
     */
    public function getTeam(int $teamId): ?array
    {
        return $this->get("teams/{$teamId}");
    }

    /**
     * Get matches for a specific date
     */
    public function getMatchesByDate(string $date): ?array
    {
        return $this->get('matches', ['date' => $date]);
    }

    /**
     * Get live matches
     */
    public function getLiveMatches(): ?array
    {
        return $this->get('matches', ['status' => 'LIVE']);
    }

    /**
     * Get match events (goals, cards, etc.) for a specific match
     */
    public function getMatchEvents(int $matchId): ?array
    {
        return $this->get("matches/{$matchId}/events");
    }

    /**
     * Get detailed match information including events
     */
    public function getMatchDetails(int $matchId): ?array
    {
        return $this->get("matches/{$matchId}");
    }

    /**
     * Get matches with events for a specific date
     */
    public function getMatchesWithEventsByDate(string $date): ?array
    {
        $matches = $this->getMatchesByDate($date);
        
        if (!$matches || !isset($matches['matches'])) {
            return null;
        }

        // Fetch events for each match
        foreach ($matches['matches'] as &$match) {
            if (isset($match['id'])) {
                $events = $this->getMatchEvents($match['id']);
                if ($events && isset($events['events'])) {
                    $match['events'] = $events['events'];
                }
            }
        }

        return $matches;
    }

    /**
     * Get live matches with events
     */
    public function getLiveMatchesWithEvents(): ?array
    {
        $matches = $this->getLiveMatches();
        
        if (!$matches || !isset($matches['matches'])) {
            return null;
        }

        // Fetch events for each live match
        foreach ($matches['matches'] as &$match) {
            if (isset($match['id'])) {
                $events = $this->getMatchEvents($match['id']);
                if ($events && isset($events['events'])) {
                    $match['events'] = $events['events'];
                }
            }
        }

        return $matches;
    }

    /**
     * Get appropriate cache duration based on endpoint type
     */
    private function getCacheDurationForEndpoint(string $endpoint, array $params = []): int
    {
        // Match events change very frequently - shorter cache
        if (str_contains($endpoint, 'events')) {
            return 60; // 1 minute for match events
        }
        
        // Live matches should have shorter cache
        if (str_contains($endpoint, 'matches') && (($params['status'] ?? null) === 'LIVE')) {
            return 0;
        }
        
        // Match data changes frequently during match days
        if (str_contains($endpoint, 'matches')) {
            return 300; // 5 minutes for matches
        }
        
        // Standings change after matches
        if (str_contains($endpoint, 'standings')) {
            return 1800; // 30 minutes for standings
        }
        
        // Teams and competitions are more static
        if (str_contains($endpoint, 'teams') || str_contains($endpoint, 'competitions')) {
            return 3600; // 1 hour for teams/competitions
        }
        
        // Default cache duration
        return $this->cacheDuration;
    }

    /**
     * Check if this is a live match request
     */
    private function isLiveMatchRequest(): bool
    {
        try {
            return app('request')->get('status') === 'LIVE';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if we're within rate limits
     */
    private function checkRateLimit(): bool
    {
        $currentMinute = Carbon::now()->format('Y-m-d H:i');
        $requestCount = Cache::get($this->rateLimitCacheKey . ':' . $currentMinute, 0);
        
        return $requestCount < $this->maxRequestsPerMinute;
    }

    /**
     * Wait for rate limit to reset
     */
    private function waitForRateLimit(): void
    {
        $secondsToWait = 60 - Carbon::now()->second;
        Log::info('Waiting for rate limit reset', ['seconds' => $secondsToWait]);
        sleep($secondsToWait);
    }

    /**
     * Track API request for rate limiting
     */
    private function trackRequest(): void
    {
        $currentMinute = Carbon::now()->format('Y-m-d H:i');
        $cacheKey = $this->rateLimitCacheKey . ':' . $currentMinute;
        
        $currentCount = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $currentCount + 1, 120); // Keep for 2 minutes
    }

    /**
     * Log API usage information
     */
    private function logApiUsage($response, string $endpoint): void
    {
        $remainingRequests = $response->header('X-Requests-Available-Minute');
        $requestsPerMinute = $response->header('X-RequestCounter-Minute');
        
        if ($remainingRequests !== null) {
            Log::info('Football Data API usage', [
                'endpoint' => $endpoint,
                'remaining_requests' => $remainingRequests,
                'requests_this_minute' => $requestsPerMinute,
                'status' => $response->status()
            ]);
            
            // Store rate limit info in cache for monitoring
            Cache::put('football_data_api_status', [
                'remaining_requests' => $remainingRequests,
                'requests_this_minute' => $requestsPerMinute,
                'last_updated' => Carbon::now(),
                'endpoint' => $endpoint
            ], 300);
        }
    }

    /**
     * Update rate limit information from API response
     */
    private function updateRateLimitInfo($response): void
    {
        $remainingRequests = $response->header('X-Requests-Available-Minute');
        
        if ($remainingRequests !== null) {
            Cache::put('football_data_rate_limit_remaining', $remainingRequests, 120);
        }
    }

    /**
     * Get current API usage status
     */
    public function getApiStatus(): array
    {
        $status = Cache::get('football_data_api_status', [
            'remaining_requests' => 'Unknown',
            'requests_this_minute' => 'Unknown',
            'last_updated' => null,
            'endpoint' => null
        ]);
        
        $currentMinute = Carbon::now()->format('Y-m-d H:i');
        $localRequests = Cache::get($this->rateLimitCacheKey . ':' . $currentMinute, 0);
        
        return array_merge($status, [
            'local_requests_this_minute' => $localRequests,
            'max_requests_per_minute' => $this->maxRequestsPerMinute,
            'is_configured' => $this->isConfigured()
        ]);
    }

    /**
     * Clear all caches related to this service
     */
    public function clearCache(): int
    {
        $clearedCount = 0;
        
        // Clear known cache patterns
        $cacheKeys = [
            'football_data_api_status',
            'football_data_rate_limit_remaining',
        ];
        
        foreach ($cacheKeys as $key) {
            if (Cache::has($key)) {
                Cache::forget($key);
                $clearedCount++;
            }
        }
        
        // Clear rate limit tracking caches (current minute and surrounding minutes)
        $currentTime = Carbon::now();
        for ($i = -2; $i <= 2; $i++) {
            $minute = $currentTime->copy()->addMinutes($i)->format('Y-m-d H:i');
            $rateLimitKey = $this->rateLimitCacheKey . ':' . $minute;
            
            if (Cache::has($rateLimitKey)) {
                Cache::forget($rateLimitKey);
                $clearedCount++;
            }
        }
        
        // Clear general football data caches (use a more targeted approach)
        $commonEndpoints = [
            'competitions', 'matches', 'standings', 'teams', 'events'
        ];
        
        foreach ($commonEndpoints as $endpoint) {
            // Try common cache key patterns
            for ($i = 0; $i < 100; $i++) {
                $testKey = 'football_data_' . md5($endpoint . serialize([])) . $i;
                if (Cache::has($testKey)) {
                    Cache::forget($testKey);
                    $clearedCount++;
                }
            }
        }
        
        Log::info('Football Data cache cleared', ['cleared_keys' => $clearedCount]);
        
        return $clearedCount;
    }

    private function generateCacheKey(string $endpoint, array $params): string
    {
        return 'football_data_' . md5($endpoint . serialize($params));
    }
}
