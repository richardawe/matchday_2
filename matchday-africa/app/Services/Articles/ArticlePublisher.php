<?php

namespace App\Services\Articles;

use App\Models\ArticlePublishLog;
use App\Models\Blog;
use App\Services\FrontPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Publishes and unpublishes an article: the editor's Publish button on /admin/blogs and the agent API
 * (/api/agent/articles/{id}/publish) both go through here, so an article goes live the same way
 * whoever presses it: status published, published_at now, a unique slug, the sitemap and the front
 * page's article fragments ("New on Matchday", hero, Latest News) cleared, and a row in
 * article_publish_logs.
 */
class ArticlePublisher
{
    public const ACTOR_AGENT = 'agent';

    public const ACTOR_EDITOR = 'editor';

    public function publish(Blog $blog, string $actor, ?int $userId = null): Blog
    {
        $metadata = $blog->metadata ?? [];
        $metadata['published_by'] = $actor;
        $blog->update(['status' => 'published', 'published_at' => now(), 'slug' => $this->slug($blog), 'metadata' => $metadata]);
        $this->after($blog, 'publish', $actor, $userId);

        return $blog;
    }

    public function unpublish(Blog $blog, string $actor, ?int $userId = null): Blog
    {
        $metadata = $blog->metadata ?? [];
        unset($metadata['published_by']);
        $blog->update(['status' => 'draft', 'published_at' => null, 'metadata' => $metadata]);
        $this->after($blog, 'unpublish', $actor, $userId);

        return $blog;
    }

    /** The article's slug, or one made from its title, never shared with another article. */
    private function slug(Blog $blog): string
    {
        $base = $blog->slug ?: (Str::slug((string) $blog->title) ?: 'article');
        $slug = $base;
        for ($i = 2; Blog::where('slug', $slug)->whereKeyNot($blog->getKey())->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function after(Blog $blog, string $action, string $actor, ?int $userId): void
    {
        Cache::forget('sitemap.xml');
        FrontPage::forgetArticles();
        if (Schema::hasTable('article_publish_logs')) {
            ArticlePublishLog::create(['blog_id' => $blog->id, 'action' => $action, 'actor' => $actor, 'user_id' => $userId, 'created_at' => now()]);
        }
    }
}
