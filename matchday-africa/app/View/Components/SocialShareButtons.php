<?php

namespace App\View\Components;

use App\Services\SocialSharingService;
use Illuminate\View\Component;
use Illuminate\View\View;

class SocialShareButtons extends Component
{
    public $content;
    public $shareCounts;
    public $showCounts;
    public $platforms;

    /**
     * Create a new component instance.
     */
    public function __construct(
        $content,
        bool $showCounts = true,
        array $platforms = ['facebook', 'twitter', 'linkedin', 'whatsapp']
    ) {
        $this->content = $content;
        $this->showCounts = $showCounts;
        $this->platforms = $platforms;
        
        // Get share counts if enabled
        if ($showCounts) {
            $socialSharingService = app(SocialSharingService::class);
            $this->shareCounts = $socialSharingService->getShareCounts($content);
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.social-share-buttons');
    }

    /**
     * Get the content type for URL generation
     */
    public function getContentType(): string
    {
        $class = get_class($this->content);
        
        switch ($class) {
            case 'App\Models\FootballMatch':
                return 'match';
            case 'App\Models\Blog':
                return 'blog';
            case 'App\Models\PredictionSet':
                return 'prediction';
            default:
                return 'content';
        }
    }

    /**
     * Get the content ID
     */
    public function getContentId(): int
    {
        return $this->content->id;
    }

    /**
     * Get platform display name
     */
    public function getPlatformName(string $platform): string
    {
        return match($platform) {
            'facebook' => 'Facebook',
            'twitter' => 'Twitter',
            'linkedin' => 'LinkedIn',
            'whatsapp' => 'WhatsApp',
            default => ucfirst($platform),
        };
    }

    /**
     * Get platform icon
     */
    public function getPlatformIcon(string $platform): string
    {
        return match($platform) {
            'facebook' => 'facebook',
            'twitter' => 'twitter',
            'linkedin' => 'linkedin',
            'whatsapp' => 'whatsapp',
            default => 'share',
        };
    }
}