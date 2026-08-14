<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class GiphyService
{
    private string $apiKey;
    private string $baseUrl;
    private string $rating;
    private int $limit;
    
    public function __construct()
    {
        $this->apiKey = config('services.giphy.api_key');
        $this->baseUrl = config('services.giphy.base_url');
        $this->rating = config('services.giphy.rating', 'pg-13');
        $this->limit = config('services.giphy.limit', 20);
    }

    /**
     * Search for GIFs
     */
    public function search(string $query, int $offset = 0): array
    {
        if (empty($this->apiKey)) {
            Log::error('Giphy API key not configured');
            return ['gifs' => [], 'total' => 0];
        }

        $cacheKey = "giphy_search_" . md5($query . $offset . $this->limit);
        
        return Cache::remember($cacheKey, 300, function () use ($query, $offset) {
            try {
                $response = Http::get("{$this->baseUrl}/gifs/search", [
                    'api_key' => $this->apiKey,
                    'q' => $query,
                    'limit' => $this->limit,
                    'offset' => $offset,
                    'rating' => $this->rating,
                    'lang' => 'en'
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    return [
                        'gifs' => collect($data['data'] ?? [])->map(function ($gif) {
                            return [
                                'id' => $gif['id'],
                                'title' => $gif['title'],
                                'url' => $gif['images']['fixed_height']['url'] ?? $gif['images']['original']['url'],
                                'preview_url' => $gif['images']['preview_gif']['url'] ?? $gif['images']['fixed_height_small']['url'],
                                'width' => $gif['images']['fixed_height']['width'] ?? 200,
                                'height' => $gif['images']['fixed_height']['height'] ?? 200,
                            ];
                        })->toArray(),
                        'total' => $data['pagination']['total_count'] ?? 0
                    ];
                }

                Log::error('Giphy API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return ['gifs' => [], 'total' => 0];
                
            } catch (Exception $e) {
                Log::error('Giphy API exception', ['error' => $e->getMessage()]);
                return ['gifs' => [], 'total' => 0];
            }
        });
    }

    /**
     * Get trending GIFs
     */
    public function trending(int $offset = 0): array
    {
        if (empty($this->apiKey)) {
            Log::error('Giphy API key not configured');
            return ['gifs' => [], 'total' => 0];
        }

        $cacheKey = "giphy_trending_" . $offset . $this->limit;
        
        return Cache::remember($cacheKey, 300, function () use ($offset) {
            try {
                $response = Http::get("{$this->baseUrl}/gifs/trending", [
                    'api_key' => $this->apiKey,
                    'limit' => $this->limit,
                    'offset' => $offset,
                    'rating' => $this->rating
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    return [
                        'gifs' => collect($data['data'] ?? [])->map(function ($gif) {
                            return [
                                'id' => $gif['id'],
                                'title' => $gif['title'],
                                'url' => $gif['images']['fixed_height']['url'] ?? $gif['images']['original']['url'],
                                'preview_url' => $gif['images']['preview_gif']['url'] ?? $gif['images']['fixed_height_small']['url'],
                                'width' => $gif['images']['fixed_height']['width'] ?? 200,
                                'height' => $gif['images']['fixed_height']['height'] ?? 200,
                            ];
                        })->toArray(),
                        'total' => $data['pagination']['total_count'] ?? 0
                    ];
                }

                Log::error('Giphy API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return ['gifs' => [], 'total' => 0];
                
            } catch (Exception $e) {
                Log::error('Giphy API exception', ['error' => $e->getMessage()]);
                return ['gifs' => [], 'total' => 0];
            }
        });
    }

    /**
     * Get football-related GIFs
     */
    public function getFootballGifs(): array
    {
        $queries = ['football', 'soccer', 'goal', 'celebration', 'referee', 'penalty'];
        $randomQuery = $queries[array_rand($queries)];
        
        return $this->search($randomQuery);
    }

    /**
     * Check if Giphy API is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}