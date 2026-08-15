<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class OpenRouterService
{
    protected $apiKey;
    protected $baseUrl;
    protected $model;
    protected $fallbackModels;
    protected $maxRetries;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
        $this->baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->model = config('services.openrouter.model', 'openai/gpt-oss-120b:free');
        $this->fallbackModels = config('services.openrouter.fallback_models', ['openai/gpt-oss-20b:free']);
        $this->maxRetries = 3;
    }

    /**
     * Generate match preview using AI
     */
    public function generateMatchPreview($matchData)
    {
        try {
            // Check API key
            if (!$this->apiKey) {
                throw new \Exception('OpenRouter API key not configured');
            }

            // Check rate limiting
            if ($this->isRateLimited()) {
                throw new \Exception('API rate limit reached');
            }

            $prompt = $this->buildMatchPreviewPrompt($matchData);
            
            $response = $this->makeApiRequest($prompt);
            
            if ($response && isset($response['choices'][0]['message']['content'])) {
                $this->incrementRequestCount();
                return $response['choices'][0]['message']['content'];
            }

            throw new \Exception('Invalid API response format');

        } catch (\Exception $e) {
            Log::error('OpenRouter API error: ' . $e->getMessage(), [
                'match_id' => $matchData['match_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    public function editFootballArticle(string $prompt): ?string
    {
        try {
            if (!$this->apiKey || $this->isRateLimited()) return null;
            $response = $this->makeApiRequest($prompt);
            $content = $response['choices'][0]['message']['content'] ?? null;
            if ($content) $this->incrementRequestCount();
            return $content;
        } catch (\Throwable $e) {
            Log::error('OpenRouter article edit failed', ['error'=>$e->getMessage()]);
            return null;
        }
    }

    /**
     * Build the match preview prompt
     */
    protected function buildMatchPreviewPrompt($matchData)
    {
        $homeTeam = $matchData['home_team'] ?? 'Home Team';
        $awayTeam = $matchData['away_team'] ?? 'Away Team';
        $league = $matchData['league'] ?? 'League';
        $date = $matchData['match_date'] ?? 'today';
        $time = $matchData['match_time'] ?? '';
        $homePosition = $matchData['home_position'] ?? '';
        $awayPosition = $matchData['away_position'] ?? '';
        $homeForm = $matchData['home_form'] ?? '';
        $awayForm = $matchData['away_form'] ?? '';
        $h2h = $matchData['head_to_head'] ?? '';

        return "You are an expert football analyst writing a comprehensive match preview for {$homeTeam} vs {$awayTeam} in {$league}.

CRITICAL INSTRUCTION: You must NOT mention any specific player names (like Dominic Calvert-Lewin, Unai Emery, etc.) anywhere in this preview. Instead, use generic terms like 'key players', 'star performers', 'attacking threats', 'defensive stalwarts', 'midfield maestros', etc. Focus entirely on team tactics, formations, strategies, and collective performance.

MATCH DETAILS:
- Date: {$date} at {$time}
- League Position: {$homePosition} vs {$awayPosition}
- Recent Form: {$homeForm} vs {$awayForm}
- Head-to-Head Record: {$h2h}

WRITE A DETAILED PREVIEW INCLUDING:

1. **MATCH CONTEXT** (2-3 sentences)
   - League standings implications
   - What's at stake for both teams
   - Historical significance if any

2. **TEAM ANALYSIS** (3-4 sentences each team)
   - Recent performance and form
   - Key strengths and weaknesses
   - Tactical approach and style of play
   - Key areas of the team and their impact

3. **HEAD-TO-HEAD INSIGHTS** (2-3 sentences)
   - Historical meetings and patterns
   - Previous results and trends
   - Psychological factors

4. **KEY BATTLEGROUNDS** (2-3 sentences)
   - Specific areas where the match will be won/lost
   - Tactical matchups to watch
   - Set pieces and special situations

5. **PREDICTION & OUTCOME** (2-3 sentences)
   - Most likely result with reasoning
   - Score prediction
   - Key factors that will decide the match

FORMATTING REQUIREMENTS:
- Use HTML tags for structure: <h3> for section headers, <p> for paragraphs, <strong> for emphasis
- Write 300-400 words total
- Use engaging, professional football language
- Include specific tactical insights and statistics
- DO NOT mention specific player names - focus on team tactics, formations, and strategies
- Use terms like key players, star performers, attacking threats instead of actual names
- Make it informative yet exciting to read

Format the response in HTML with proper paragraphs and emphasis.";
    }

    /**
     * Make API request to OpenRouter
     */
    protected function makeApiRequest($prompt)
    {
        $models = array_values(array_unique(array_filter([
            $this->model,
            ...$this->fallbackModels,
        ])));
        $lastError = null;

        foreach ($models as $model) {
            $retries = 0;

            while ($retries < $this->maxRetries) {
                try {
                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => 'Matchday Africa'
                    ])->post($this->baseUrl . '/chat/completions', [
                        'model' => $model,
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $prompt
                            ]
                        ],
                        'max_tokens' => 800,
                        'temperature' => 0.7
                    ]);

                    if ($response->successful()) {
                        Log::info('OpenRouter request completed', ['model' => $model]);
                        return $response->json();
                    }

                    // Handle rate limiting
                    if ($response->status() === 429) {
                        $retries++;
                        $waitTime = pow(2, $retries) * 1000; // Exponential backoff
                        usleep($waitTime * 1000);
                        continue;
                    }

                    $detail = $response->json('error.message') ?: $response->body();
                    $lastError = new \Exception("OpenRouter model {$model} failed ({$response->status()}): {$detail}");
                    Log::warning('OpenRouter model unavailable; trying fallback', [
                        'model' => $model,
                        'error' => $lastError->getMessage(),
                    ]);
                    break;

                } catch (\Exception $e) {
                    $lastError = $e;
                    $retries++;
                    if ($retries >= $this->maxRetries) {
                        Log::warning('OpenRouter model unavailable; trying fallback', [
                            'model' => $model,
                            'error' => $e->getMessage(),
                        ]);
                        break;
                    }

                    $waitTime = pow(2, $retries) * 1000;
                    usleep($waitTime * 1000);
                }
            }
        }

        throw $lastError ?? new \Exception('No OpenRouter model is configured');
    }

    /**
     * Check if we're rate limited
     */
    protected function isRateLimited()
    {
        $dailyCount = Cache::get('openrouter_daily_requests', 0);
        $maxDaily = config('services.openrouter.max_daily_requests', 1000);
        
        return $dailyCount >= $maxDaily;
    }

    /**
     * Increment request count
     */
    protected function incrementRequestCount()
    {
        $key = 'openrouter_daily_requests';
        $count = Cache::get($key, 0);
        Cache::put($key, $count + 1, now()->endOfDay());
    }

    /**
     * Get current API usage
     */
    public function getApiUsage()
    {
        return [
            'daily_requests' => Cache::get('openrouter_daily_requests', 0),
            'max_daily_requests' => config('services.openrouter.max_daily_requests', 1000),
            'remaining_requests' => config('services.openrouter.max_daily_requests', 1000) - Cache::get('openrouter_daily_requests', 0)
        ];
    }

    /**
     * Test API connection
     */
    public function testConnection()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json'
            ])->get($this->baseUrl . '/models');

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
