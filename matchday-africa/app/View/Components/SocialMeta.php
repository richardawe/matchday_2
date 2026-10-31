<?php

namespace App\View\Components;

use App\Services\SocialMetaService;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The <head> tags for SEO and link previews. A page passes its subject as $content, or sets
 * @section('meta_title'), @section('meta_description'), @section('meta_image') and
 * @section('meta_robots') for pages without one.
 */
class SocialMeta extends Component
{
    public array $metaData;

    public function __construct(public $content = null)
    {
        $views = app('view');
        $sections = array_filter([
            'title' => trim($views->yieldContent('meta_title')),
            'description' => trim($views->yieldContent('meta_description')),
            'image' => trim($views->yieldContent('meta_image')),
            'robots' => trim($views->yieldContent('meta_robots')),
        ]);
        // Section text arrives HTML-escaped; the meta view escapes again on output.
        $sections = array_map(fn ($v) => html_entity_decode($v, ENT_QUOTES | ENT_HTML5), $sections);

        $this->metaData = app(SocialMetaService::class)->getSocialPreviewData($content, $sections);
    }

    public function render(): View
    {
        return view('components.social-meta');
    }
}
