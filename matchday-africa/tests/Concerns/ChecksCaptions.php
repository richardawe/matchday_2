<?php

namespace Tests\Concerns;

use App\Models\GrowthPost;

/** Auto-post captions carry no emoji (owner's choice, 8 Nov 2026). */
trait ChecksCaptions
{
    protected const CAPTION_EMOJI = ['👇', '🎬', '🎨', '🥁'];

    protected function assertCaptionsHaveNoEmoji(): void
    {
        $posts = GrowthPost::all();
        $this->assertNotEmpty($posts, 'no captions were generated to check');
        foreach ($posts as $post) {
            foreach (self::CAPTION_EMOJI as $emoji) {
                $this->assertStringNotContainsString($emoji, (string) $post->message, "{$post->type} post {$post->content_key} contains {$emoji}");
            }
        }
    }
}
