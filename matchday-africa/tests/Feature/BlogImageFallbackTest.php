<?php

namespace Tests\Feature;

use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlogImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_article_without_a_picture_shows_no_broken_image(): void
    {
        $blog = $this->blog(null);

        $this->get(route('blogs.show', $blog))->assertOk()
            ->assertDontSee('default-blog-image.jpg', false)
            ->assertDontSee('md-article-image', false);
        $this->get(route('blogs.index'))->assertOk()->assertSee(route('images.social-default', ['v' => '20260823']), false);
    }

    public function test_an_article_whose_file_is_missing_shows_no_logo_in_place_of_the_picture(): void
    {
        Storage::fake('public');
        $blog = $this->blog('blog-images/gone.jpg');

        $this->get(route('blogs.show', $blog))->assertOk()
            ->assertDontSee('matchday-africa-logo.svg', false)
            ->assertDontSee('md-article-image', false);
    }

    public function test_an_article_with_its_file_shows_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('blog-images/here.jpg', 'x');
        $blog = $this->blog('blog-images/here.jpg');

        $this->get(route('blogs.show', $blog))->assertOk()->assertSee(route('blogs.image', 'here.jpg'), false);
    }

    private function blog(?string $image): Blog
    {
        return Blog::create(['title' => 'Story '.uniqid(), 'slug' => 'story-'.uniqid(), 'content' => str_repeat('Body. ', 20),
            'status' => 'published', 'published_at' => now()->subHour(), 'featured_image' => $image]);
    }
}
