<?php
namespace Tests\Feature;
use App\Services\MatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class MatchSyncRequestTest extends TestCase {
    use RefreshDatabase;
    public function test_date_sync_uses_supported_football_data_date_range_parameters():void{
        Http::fake(['*'=>Http::response(['matches'=>[]])]);
        app(MatchService::class)->syncMatchesByDate('2026-08-15');
        Http::assertSent(fn($request)=>$request['dateFrom']==='2026-08-15'&&$request['dateTo']==='2026-08-15'&&!isset($request['date']));
    }
    public function test_upcoming_sync_stays_within_provider_maximum_range():void{
        $this->travelTo('2026-08-21 12:00:00');
        Http::fake(['*'=>Http::response(['matches'=>[]])]);
        app(MatchService::class)->syncUpcomingMatches(10);
        Http::assertSent(fn($request)=>$request['dateFrom']==='2026-08-21'&&$request['dateTo']==='2026-08-31');
    }
}
