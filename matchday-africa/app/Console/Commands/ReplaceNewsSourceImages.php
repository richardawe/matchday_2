<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Services\NewsImages;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Swaps news pictures taken from source sites (BBC, Getty, Reuters, AP, AFP, PA, any host but ours or
 * Wikimedia) for our own: the match card, a credited Commons photo, else the branded text card.
 * Run with --dry-run first: it lists every article it would change and what it would become.
 */
class ReplaceNewsSourceImages extends Command
{
    protected $signature = 'news:replace-source-images {--dry-run : List the articles and their replacement without changing anything} {--limit= : At most this many articles}';

    protected $description = 'Replace news pictures taken from source sites with our own match card, Commons photo or text card';

    public function handle(NewsImages $images): int
    {
        $dry = (bool) $this->option('dry-run');
        $blogs = $images->thirdParty($this->option('limit') ? max(1, (int) $this->option('limit')) : null);
        if ($blogs->isEmpty()) {
            $this->info('No articles use a picture from another site.');

            return self::SUCCESS;
        }
        $rows = [];
        $done = 0;
        $failed = 0;
        foreach ($blogs as $blog) {
            /** @var Blog $blog */
            $host = NewsImages::sourceHost($blog);
            $old = (string) $blog->featured_image;
            try {
                if ($dry) {
                    $choice = $images->choose($blog, draw: false);
                    $new = $choice['image'];
                    $kind = $choice['metadata']['image_kind'] ?? '';
                } else {
                    $images->replace($blog);
                    $new = (string) $blog->featured_image;
                    $kind = (string) data_get($blog->metadata, 'image_kind');
                    $done++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $new = 'failed: '.Str::limit($e->getMessage(), 60);
                $kind = '';
            }
            $rows[] = [$blog->id, Str::limit($blog->title, 50), $host, Str::limit($old, 50), $kind, Str::limit($new, 50)];
        }
        $this->table(['id', 'title', 'source host', 'current picture', 'replacement', 'new picture'], $rows);
        if ($dry) {
            $this->info("Dry run: {$blogs->count()} article(s) would change. Run again without --dry-run to replace them.");
        } else {
            $this->info("Replaced {$done} picture(s); failed {$failed}.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
