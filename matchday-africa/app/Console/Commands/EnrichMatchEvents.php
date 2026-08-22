<?php
namespace App\Console\Commands;
use App\Services\ApiFootballEnrichmentService;
use Illuminate\Console\Command;
class EnrichMatchEvents extends Command {
    protected $signature='matches:enrich-events {date? : YYYY-MM-DD, defaults to today}';
    protected $description='Enrich local fixtures with API-Football goals, cards and statistics';
    public function handle(ApiFootballEnrichmentService $service):int{
        $result=$service->syncDate($this->argument('date')?:now()->toDateString());
        if(!$result['configured']){$this->warn('API_FOOTBALL_KEY is not configured; enrichment skipped.');return self::SUCCESS;}
        $this->info("Eligible: {$result['eligible']}; provider fixtures: {$result['fixtures']}; matched: {$result['matched']}; events saved: {$result['events']}; calls now: {$result['calls']}; calls tracked today: {$result['daily_used']}; provider quota remaining: ".($result['remaining']??'unknown'));
        return self::SUCCESS;
    }
}
