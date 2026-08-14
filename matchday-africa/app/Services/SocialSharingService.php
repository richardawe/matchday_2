<?php

namespace App\Services;

use App\Models\SocialShare;
use App\Models\FootballMatch;
use App\Models\Blog;
use App\Models\PredictionSet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SocialSharingService
{
    /**
     * Generate share URL for a specific platform
     */
    public function generateShareUrl($content, string $platform): string
    {
        $url = $this->getContentUrl($content);
        $text = $this->generateShareText($content, $platform);

        switch ($platform) {
            case 'facebook':
                return "https://www.facebook.com/sharer/sharer.php?u=" . urlencode($url);
            
            case 'twitter':
                return "https://twitter.com/intent/tweet?url=" . urlencode($url) . "&text=" . urlencode($text);
            
            case 'linkedin':
                return "https://www.linkedin.com/sharing/share-offsite/?url=" . urlencode($url);
            
            case 'whatsapp':
                return "https://wa.me/?text=" . urlencode($text . " " . $url);
            
            default:
                return $url;
        }
    }

    /**
     * Track a social share
     */
    public function trackShare($content, string $platform, ?string $shareUrl = null): SocialShare
    {
        return SocialShare::create([
            'user_id' => Auth::id(),
            'shareable_type' => get_class($content),
            'shareable_id' => $content->id,
            'platform' => $platform,
            'share_url' => $shareUrl,
            'shared_at' => now(),
        ]);
    }

    /**
     * Get share counts for content
     */
    public function getShareCounts($content): array
    {
        $shares = SocialShare::where('shareable_type', get_class($content))
            ->where('shareable_id', $content->id)
            ->selectRaw('platform, COUNT(*) as count')
            ->groupBy('platform')
            ->pluck('count', 'platform')
            ->toArray();

        return [
            'facebook' => $shares['facebook'] ?? 0,
            'twitter' => $shares['twitter'] ?? 0,
            'linkedin' => $shares['linkedin'] ?? 0,
            'whatsapp' => $shares['whatsapp'] ?? 0,
            'total' => array_sum($shares),
        ];
    }

    /**
     * Generate share text for different platforms
     */
    public function generateShareText($content, string $platform): string
    {
        $baseText = $this->getBaseShareText($content);
        
        // Platform-specific adjustments
        switch ($platform) {
            case 'twitter':
                // Twitter has character limits
                return strlen($baseText) > 200 ? substr($baseText, 0, 197) . '...' : $baseText;
            
            case 'whatsapp':
                // WhatsApp prefers shorter messages
                return strlen($baseText) > 150 ? substr($baseText, 0, 147) . '...' : $baseText;
            
            default:
                return $baseText;
        }
    }

    /**
     * Get the URL for the content
     */
    protected function getContentUrl($content): string
    {
        if ($content instanceof FootballMatch) {
            return route('matches.show', $content);
        }
        
        if ($content instanceof Blog) {
            return route('blogs.show', $content);
        }
        
        if ($content instanceof PredictionSet) {
            return route('predictions.show', $content);
        }

        return config('app.url');
    }

    /**
     * Generate base share text based on content type
     */
    protected function getBaseShareText($content): string
    {
        if ($content instanceof FootballMatch) {
            $homeTeam = $content->homeTeam ? $content->homeTeam->name : 'Home Team';
            $awayTeam = $content->awayTeam ? $content->awayTeam->name : 'Away Team';
            $league = $content->league ? $content->league->name : 'League';
            $matchDate = $content->match_date ? $content->match_date->format('M j, Y H:i') : 'TBD';
            
            return "⚽ {$homeTeam} vs {$awayTeam} in {$league} on {$matchDate}. Check out this match on Matchday Africa! #Football #MatchdayAfrica";
        }
        
        if ($content instanceof Blog) {
            $title = $content->title;
            $excerpt = $content->excerpt ?: substr(strip_tags($content->content), 0, 100) . '...';
            
            return "📰 {$title} - {$excerpt} Read more on Matchday Africa! #Football #Blog #MatchdayAfrica";
        }
        
        if ($content instanceof PredictionSet) {
            $title = $content->title;
            $matchCount = $content->matches()->count();
            
            return "🎯 {$title} - Make your predictions for {$matchCount} matches! Join the prediction challenge on Matchday Africa! #Predictions #Football #MatchdayAfrica";
        }

        return "Check this out on Matchday Africa! #Football #MatchdayAfrica";
    }

    /**
     * Get popular content for sharing suggestions
     */
    public function getPopularContent(int $limit = 5): array
    {
        $popularMatches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->where('match_date', '>=', now())
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $popularBlogs = Blog::published()
            ->orderBy('view_count', 'desc')
            ->limit($limit)
            ->get();

        return [
            'matches' => $popularMatches,
            'blogs' => $popularBlogs,
        ];
    }

    /**
     * Get sharing analytics
     */
    public function getSharingAnalytics(int $days = 30): array
    {
        $startDate = now()->subDays($days);

        $totalShares = SocialShare::where('shared_at', '>=', $startDate)->count();
        
        $sharesByPlatform = SocialShare::where('shared_at', '>=', $startDate)
            ->selectRaw('platform, COUNT(*) as count')
            ->groupBy('platform')
            ->pluck('count', 'platform')
            ->toArray();

        $sharesByContent = SocialShare::where('shared_at', '>=', $startDate)
            ->selectRaw('shareable_type, COUNT(*) as count')
            ->groupBy('shareable_type')
            ->pluck('count', 'shareable_type')
            ->toArray();

        return [
            'total_shares' => $totalShares,
            'shares_by_platform' => $sharesByPlatform,
            'shares_by_content' => $sharesByContent,
            'period_days' => $days,
        ];
    }
}
