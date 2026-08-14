<?php

namespace App\View\Components;

use App\Services\SocialMetaService;
use Illuminate\View\Component;
use Illuminate\View\View;

class SocialMeta extends Component
{
    public $content;
    public $metaData;

    /**
     * Create a new component instance.
     */
    public function __construct($content = null)
    {
        $this->content = $content;
        
        if ($content) {
            $socialMetaService = app(SocialMetaService::class);
            $this->metaData = $socialMetaService->getSocialPreviewData($content);
        } else {
            // Default meta data for pages without specific content
            $this->metaData = $this->getDefaultMetaData();
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.social-meta');
    }

    /**
     * Get default meta data for pages without specific content
     */
    protected function getDefaultMetaData(): array
    {
        return [
            'title' => 'Matchday Africa - Football Match Tracking & Predictions',
            'description' => 'Your ultimate destination for football match tracking, live scores, predictions, and more! Follow your favorite teams and leagues.',
            'image' => asset('images/social-default.png'),
            'url' => config('app.url'),
            'open_graph' => [
                'og:type' => 'website',
                'og:site_name' => config('app.name', 'Matchday Africa'),
                'og:title' => 'Matchday Africa - Football Match Tracking & Predictions',
                'og:description' => 'Your ultimate destination for football match tracking, live scores, predictions, and more!',
                'og:image' => asset('images/social-default.png'),
                'og:url' => config('app.url'),
            ],
            'twitter_card' => [
                'twitter:card' => 'summary_large_image',
                'twitter:site' => '@matchdayafrica',
                'twitter:title' => 'Matchday Africa - Football Match Tracking & Predictions',
                'twitter:description' => 'Your ultimate destination for football match tracking, live scores, predictions, and more!',
                'twitter:image' => asset('images/social-default.png'),
            ],
        ];
    }
}