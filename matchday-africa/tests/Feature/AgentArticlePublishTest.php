<?php

namespace Tests\Feature;

use App\Models\ArticlePublishLog;
use App\Models\Blog;
use App\Models\User;
use App\Services\Agent\AgentAccess;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AgentArticlePublishTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://matchday.africa']);
        [$this->agent] = app(AgentAccess::class)->upsert('agent@matchday.africa', 'Matchday agent', 'correct-horse-battery');
        [, $this->token] = app(AgentAccess::class)->issueToken($this->agent);
    }

    private function api(): self
    {
        return $this->withToken($this->token);
    }

    private function agentDraft(string $title = 'Iwobi rescues Fulham'): Blog
    {
        $id = $this->api()->postJson('/api/agent/articles', ['title' => $title, 'content' => '<p>Late goal.</p>'])
            ->assertCreated()->assertJsonPath('status', 'draft')->json('id');

        return Blog::findOrFail($id);
    }

    private function editorPost(): Blog
    {
        return Blog::create(['title' => 'Editor column', 'slug' => 'editor-column', 'content' => '<p>Ours.</p>',
            'status' => 'draft', 'author_name' => 'Editor']);
    }

    public function test_the_agent_publishes_its_own_draft_like_the_editor_button(): void
    {
        $blog = $this->agentDraft();
        Cache::put('sitemap.xml', 'old', 3600);
        Cache::put('front-page:fresh:10', ['old'], 60);
        Cache::put('front-page:hero-article', 0, 60);

        $this->api()->postJson("/api/agent/articles/{$blog->id}/publish")->assertOk()
            ->assertJsonPath('status', 'published')->assertJsonPath('published_by', 'agent')
            ->assertJsonPath('url', 'https://matchday.africa/blogs/iwobi-rescues-fulham');

        $blog->refresh();
        $this->assertSame('published', $blog->status);
        $this->assertNotNull($blog->published_at);
        $this->assertSame('iwobi-rescues-fulham', $blog->slug);
        $this->assertTrue($blog->publishedByAgent());
        $this->assertTrue(Blog::published()->whereKey($blog->id)->exists());
        $this->assertFalse(Cache::has('sitemap.xml'));
        $this->assertFalse(Cache::has('front-page:fresh:10'));
        $this->assertFalse(Cache::has('front-page:hero-article'));
        $this->get('/sitemap.xml')->assertOk()->assertSee('/blogs/iwobi-rescues-fulham', false);

        $log = ArticlePublishLog::sole();
        $this->assertSame([$blog->id, 'publish', 'agent', $this->agent->id], [$log->blog_id, $log->action, $log->actor, $log->user_id]);
        $this->assertNotNull($log->created_at);
    }

    public function test_the_agent_unpublishes_its_own_article_back_to_draft(): void
    {
        $blog = $this->agentDraft();
        $this->api()->postJson("/api/agent/articles/{$blog->id}/publish")->assertOk();

        $this->api()->postJson("/api/agent/articles/{$blog->id}/unpublish")->assertOk()
            ->assertJsonPath('status', 'draft')->assertJsonPath('url', null);

        $blog->refresh();
        $this->assertSame('draft', $blog->status);
        $this->assertNull($blog->published_at);
        $this->assertFalse($blog->publishedByAgent());
        $this->assertSame(['publish', 'unpublish'], ArticlePublishLog::orderBy('id')->pluck('action')->all());
    }

    public function test_create_saves_a_draft_by_default_and_publishes_in_the_same_call_on_request(): void
    {
        $this->agentDraft('Draft first');
        $this->assertSame(0, ArticlePublishLog::count());

        $live = $this->api()->postJson('/api/agent/articles', ['title' => 'Live at once', 'content' => '<p>Now.</p>', 'publish' => true])
            ->assertCreated()->assertJsonPath('status', 'published')->assertJsonPath('published_by', 'agent')->json('id');
        $this->assertSame('publish', ArticlePublishLog::where('blog_id', $live)->value('action'));
    }

    public function test_publishing_answers_403_when_an_admin_switches_it_off(): void
    {
        $blog = $this->agentDraft();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/settings', ['agent_can_publish' => '0'])->assertRedirect();
        $this->assertFalse(SiteSettings::agentCanPublish());

        $this->api()->postJson("/api/agent/articles/{$blog->id}/publish")->assertForbidden();
        $this->api()->postJson("/api/agent/articles/{$blog->id}/unpublish")->assertForbidden();
        $this->api()->patchJson("/api/agent/articles/{$blog->id}", ['publish' => true])->assertForbidden();
        $this->api()->postJson('/api/agent/articles', ['title' => 'Blocked', 'content' => '<p>x</p>', 'publish' => true])->assertForbidden();
        $this->assertSame('draft', $blog->fresh()->status);
        $this->assertFalse(Blog::where('title', 'Blocked')->exists());
        // Drafts still work while publishing is off.
        $this->agentDraft('Still a draft');
        $this->assertSame(0, ArticlePublishLog::count());

        $this->actingAs($admin)->post('/admin/settings', ['agent_can_publish' => '1'])->assertRedirect();
        $this->api()->postJson("/api/agent/articles/{$blog->id}/publish")->assertOk();
    }

    public function test_the_env_default_can_switch_it_off_too(): void
    {
        config(['agent.can_publish' => false]);
        $blog = $this->agentDraft();
        $this->api()->postJson("/api/agent/articles/{$blog->id}/publish")->assertForbidden();
    }

    public function test_editor_written_posts_are_off_limits(): void
    {
        $post = $this->editorPost();

        $this->api()->postJson("/api/agent/articles/{$post->id}/publish")->assertForbidden();
        $post->update(['status' => 'published', 'published_at' => now()]);
        $this->api()->postJson("/api/agent/articles/{$post->id}/unpublish")->assertForbidden();
        $this->assertSame('published', $post->fresh()->status);
        $this->api()->postJson('/api/agent/articles/99999/publish')->assertNotFound();
        $this->assertSame(0, ArticlePublishLog::count());
    }

    public function test_a_bad_or_missing_token_is_rejected(): void
    {
        $blog = $this->agentDraft();

        $this->withToken('not-a-real-token')->postJson("/api/agent/articles/{$blog->id}/publish")->assertUnauthorized();
        $this->withToken('not-a-real-token')->postJson("/api/agent/articles/{$blog->id}/unpublish")->assertUnauthorized();
        $this->flushHeaders()->postJson("/api/agent/articles/{$blog->id}/publish")->assertUnauthorized();
        $this->assertSame('draft', $blog->fresh()->status);
    }

    public function test_admin_blogs_shows_the_agent_badge_and_the_editor_button_logs_too(): void
    {
        $agentPost = $this->agentDraft();
        $this->api()->postJson("/api/agent/articles/{$agentPost->id}/publish")->assertOk();
        $editor = $this->editorPost();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post("/admin/blogs/{$editor->id}/toggle-status")->assertRedirect();
        $this->assertSame('published', $editor->fresh()->status);
        $this->assertFalse($editor->fresh()->publishedByAgent());
        $this->assertSame('editor', ArticlePublishLog::where('blog_id', $editor->id)->value('actor'));

        $html = $this->actingAs($admin)->get('/admin/blogs')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-agent-published'));
        $this->assertStringContainsString('Published by agent', $html);
        $this->assertStringContainsString($agentPost->fresh()->published_at->timezone(config('app.timezone'))->format('j M Y, H:i'), $html);
        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Agent publishing');
    }
}
