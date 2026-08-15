<?php

namespace App\Console\Commands;

use App\Services\FootballNewsService;
use Illuminate\Console\Command;

class BackfillNewsImages extends Command
{
    protected $signature = 'news:backfill-images {--limit=20 : Maximum recent automated posts to inspect}';
    protected $description = 'Download source images for automated football news posts that have none';

    public function handle(FootballNewsService $news): int
    {
        $result = $news->backfillImages((int) $this->option('limit'));
        $this->info("Images added: {$result['updated']}; unavailable: {$result['missing']}");

        return self::SUCCESS;
    }
}
