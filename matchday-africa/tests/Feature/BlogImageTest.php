<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlogImageTest extends TestCase
{
    public function test_blog_image_is_served_without_a_public_storage_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('blog-images/story.jpg', 'image-bytes');

        $this->get('/media/blog/story.jpg')
            ->assertOk()
            ->assertHeader('cache-control', 'immutable, max-age=604800, public');
    }

    public function test_missing_blog_image_returns_not_found(): void
    {
        Storage::fake('public');

        $this->get('/media/blog/missing.jpg')->assertNotFound();
    }
}
