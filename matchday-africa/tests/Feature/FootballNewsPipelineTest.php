<?php
namespace Tests\Feature;
use App\Models\Blog;
use App\Services\FootballNewsService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FootballNewsPipelineTest extends TestCase {
    use RefreshDatabase;
    public function test_feed_story_is_deduplicated_edited_attributed_and_published():void{
        $url='https://example.test/report';
        $imageUrl='https://images.example.test/story.jpg';
        $feed='<?xml version="1.0"?><rss version="2.0"><channel><item><title>Ghana prepare for a major qualifier</title><link>'.$url.'</link><guid>story-1</guid><pubDate>'.now()->toRfc2822String().'</pubDate><description>Ghana have announced preparations for their next qualifying football match.</description></item></channel></rss>';
        Storage::fake('public');
        Http::fake([
            $url=>Http::response('<html><head><meta property="og:image" content="'.$imageUrl.'"></head><body><main><p>Ghana have announced preparations for their next qualifying football match.</p></main></body></html>',200,['Content-Type'=>'text/html']),
            $imageUrl=>Http::response('fake-image-bytes',200,['Content-Type'=>'image/jpeg']),
            '*'=>Http::response($feed,200,['Content-Type'=>'application/rss+xml']),
        ]);
        $editor=Mockery::mock(OpenRouterService::class);
        $editor->shouldReceive('editFootballArticle')->once()->andReturn('TITLE: Ghana Set Their Focus on Qualifier' . "\n" . 'EXCERPT: Ghana begin preparations for their next qualifying fixture.' . "\n" . 'BODY: <p>Ghana have started preparations for their next qualifying football match.</p><p>The announcement places attention firmly on the upcoming fixture.</p><p>Matchday Africa will follow confirmed developments as they emerge.</p><p><a href="'.$url.'" rel="nofollow noopener" target="_blank">Read the original report at BBC Sport</a></p>');
        $this->app->instance(OpenRouterService::class,$editor);
        $result=app(FootballNewsService::class)->publish(1);
        $this->assertSame(1,$result['success']);
        $this->assertDatabaseCount('blogs',1);
        $blog=Blog::first();
        $this->assertSame('published',$blog->status);
        $this->assertStringContainsString($url,$blog->content);
        $this->assertNotNull($blog->featured_image);
        Storage::disk('public')->assertExists($blog->featured_image);
        $this->assertSame($imageUrl,$blog->metadata['source_image_url']);
        $this->assertTrue($blog->metadata['automated']);
        app(FootballNewsService::class)->discover();
        $this->assertDatabaseCount('news_candidates',1);
    }
}
