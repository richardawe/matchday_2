<?php
namespace App\Console\Commands;
use App\Services\FootballNewsService;
use App\Services\SyncRunService;
use Illuminate\Console\Command;
class PublishDailyFootballNews extends Command {
    protected $signature='news:publish-daily {--limit=1}';
    protected $description='Select, edit, validate and publish sourced football briefs';
    public function handle(FootballNewsService $news,SyncRunService $runs):int{
        $result=$runs->run('news:publish',fn()=>$news->publish((int)$this->option('limit')));
        $this->info($result['message']);return $result['errors']?self::FAILURE:self::SUCCESS;
    }
}
