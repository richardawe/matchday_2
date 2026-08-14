<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\Blog;
use App\Models\PredictionSet;
use Illuminate\Support\Facades\Storage;

class SocialMetaService
{
    /**
     * Generate Open Graph meta tags for content
     */
    public function generateOpenGraphTags($content): array
    {
        $baseUrl = config('app.url');
        $siteName = config('app.name', 'Matchday Africa');
        
        $meta = [
            'og:type' => 'website',
            'og:site_name' => $siteName,
            'og:url' => $this->getContentUrl($content),
        ];

        if ($content instanceof FootballMatch) {
            $meta = array_merge($meta, $this->getMatchOpenGraphTags($content, $baseUrl));
        } elseif ($content instanceof Blog) {
            $meta = array_merge($meta, $this->getBlogOpenGraphTags($content, $baseUrl));
        } elseif ($content instanceof PredictionSet) {
            $meta = array_merge($meta, $this->getPredictionOpenGraphTags($content, $baseUrl));
        }

        return $meta;
    }

    /**
     * Generate Twitter Card meta tags for content
     */
    public function generateTwitterCardTags($content): array
    {
        $meta = [
            'twitter:card' => 'summary_large_image',
            'twitter:site' => '@matchdayafrica', // Replace with actual Twitter handle
        ];

        if ($content instanceof FootballMatch) {
            $meta = array_merge($meta, $this->getMatchTwitterTags($content));
        } elseif ($content instanceof Blog) {
            $meta = array_merge($meta, $this->getBlogTwitterTags($content));
        } elseif ($content instanceof PredictionSet) {
            $meta = array_merge($meta, $this->getPredictionTwitterTags($content));
        }

        return $meta;
    }

    /**
     * Generate social preview image for content
     */
    public function generateSocialImage($content): string
    {
        // For now, return a default image or use existing featured images
        // In a full implementation, you might generate dynamic images
        
        if ($content instanceof FootballMatch) {
            return $this->getMatchSocialImage($content);
        }
        
        if ($content instanceof Blog && $content->featured_image) {
            return $this->getBlogSocialImage($content);
        }
        
        if ($content instanceof PredictionSet) {
            return $this->getPredictionSocialImage($content);
        }

        // Default social image
        return asset('images/social-default.png');
    }

    /**
     * Get social preview data for content
     */
    public function getSocialPreviewData($content): array
    {
        return [
            'title' => $this->getContentTitle($content),
            'description' => $this->getContentDescription($content),
            'image' => $this->generateSocialImage($content),
            'url' => $this->getContentUrl($content),
            'open_graph' => $this->generateOpenGraphTags($content),
            'twitter_card' => $this->generateTwitterCardTags($content),
        ];
    }

    /**
     * Get content URL
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
     * Get content title
     */
    protected function getContentTitle($content): string
    {
        if ($content instanceof FootballMatch) {
            $homeTeam = $content->homeTeam ? $content->homeTeam->name : 'Home Team';
            $awayTeam = $content->awayTeam ? $content->awayTeam->name : 'Away Team';
            return "{$homeTeam} vs {$awayTeam} - Matchday Africa";
        }
        
        if ($content instanceof Blog) {
            return $content->title . " - Matchday Africa";
        }
        
        if ($content instanceof PredictionSet) {
            return $content->name . " - Prediction Challenge - Matchday Africa";
        }

        return "Matchday Africa - Football Match Tracking & Predictions";
    }

    /**
     * Get content description
     */
    protected function getContentDescription($content): string
    {
        if ($content instanceof FootballMatch) {
            $homeTeam = $content->homeTeam ? $content->homeTeam->name : 'Home Team';
            $awayTeam = $content->awayTeam ? $content->awayTeam->name : 'Away Team';
            $league = $content->league ? $content->league->name : 'League';
            $matchDate = $content->match_date ? $content->match_date->format('M j, Y H:i') : 'TBD';
            
            return "Watch {$homeTeam} vs {$awayTeam} in {$league} on {$matchDate}. Live scores, match previews, and more on Matchday Africa!";
        }
        
        if ($content instanceof Blog) {
            return $content->excerpt ?: substr(strip_tags($content->content), 0, 160) . '...';
        }
        
        if ($content instanceof PredictionSet) {
            $matchCount = $content->matches()->count();
            return "Join the prediction challenge! Make your predictions for {$matchCount} matches and compete with other football fans on Matchday Africa!";
        }

        return "Matchday Africa - Your ultimate destination for football match tracking, live scores, predictions, and more!";
    }

    /**
     * Get match-specific Open Graph tags
     */
    protected function getMatchOpenGraphTags(FootballMatch $match, string $baseUrl): array
    {
        $homeTeam = $match->homeTeam ? $match->homeTeam->name : 'Home Team';
        $awayTeam = $match->awayTeam ? $match->awayTeam->name : 'Away Team';
        $league = $match->league ? $match->league->name : 'League';
        
        return [
            'og:title' => "{$homeTeam} vs {$awayTeam} - Matchday Africa",
            'og:description' => $this->getContentDescription($match),
            'og:image' => $this->generateSocialImage($match),
            'og:image:width' => '1200',
            'og:image:height' => '630',
            'og:image:alt' => "{$homeTeam} vs {$awayTeam} in {$league}",
        ];
    }

    /**
     * Get blog-specific Open Graph tags
     */
    protected function getBlogOpenGraphTags(Blog $blog, string $baseUrl): array
    {
        return [
            'og:title' => $blog->title . " - Matchday Africa",
            'og:description' => $this->getContentDescription($blog),
            'og:image' => $this->generateSocialImage($blog),
            'og:image:width' => '1200',
            'og:image:height' => '630',
            'og:image:alt' => $blog->title,
            'article:author' => $blog->author_name ?? 'Admin',
            'article:published_time' => $blog->published_at ? $blog->published_at->toISOString() : null,
        ];
    }

    /**
     * Get prediction-specific Open Graph tags
     */
    protected function getPredictionOpenGraphTags(PredictionSet $prediction, string $baseUrl): array
    {
        return [
            'og:title' => $prediction->name . " - Prediction Challenge - Matchday Africa",
            'og:description' => $this->getContentDescription($prediction),
            'og:image' => $this->generateSocialImage($prediction),
            'og:image:width' => '1200',
            'og:image:height' => '630',
            'og:image:alt' => $prediction->name . " Prediction Challenge",
        ];
    }

    /**
     * Get match-specific Twitter tags
     */
    protected function getMatchTwitterTags(FootballMatch $match): array
    {
        $homeTeam = $match->homeTeam ? $match->homeTeam->name : 'Home Team';
        $awayTeam = $match->awayTeam ? $match->awayTeam->name : 'Away Team';
        
        return [
            'twitter:title' => "{$homeTeam} vs {$awayTeam} - Matchday Africa",
            'twitter:description' => $this->getContentDescription($match),
            'twitter:image' => $this->generateSocialImage($match),
        ];
    }

    /**
     * Get blog-specific Twitter tags
     */
    protected function getBlogTwitterTags(Blog $blog): array
    {
        return [
            'twitter:title' => $blog->title . " - Matchday Africa",
            'twitter:description' => $this->getContentDescription($blog),
            'twitter:image' => $this->generateSocialImage($blog),
        ];
    }

    /**
     * Get prediction-specific Twitter tags
     */
    protected function getPredictionTwitterTags(PredictionSet $prediction): array
    {
        return [
            'twitter:title' => $prediction->name . " - Prediction Challenge - Matchday Africa",
            'twitter:description' => $this->getContentDescription($prediction),
            'twitter:image' => $this->generateSocialImage($prediction),
        ];
    }

    /**
     * Get match social image
     */
    protected function getMatchSocialImage(FootballMatch $match): string
    {
        // Use team logos or generate a match image
        if ($match->homeTeam && $match->awayTeam) {
            return asset('images/social-match-default.png');
        }
        
        return asset('images/social-default.png');
    }

    /**
     * Get blog social image
     */
    protected function getBlogSocialImage(Blog $blog): string
    {
        if ($blog->featured_image) {
            return $blog->featured_image_url;
        }
        
        return asset('images/social-blog-default.png');
    }

    /**
     * Get prediction social image
     */
    protected function getPredictionSocialImage(PredictionSet $prediction): string
    {
        return asset('images/social-prediction-default.png');
    }
}
