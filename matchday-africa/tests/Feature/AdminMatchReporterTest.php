<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMatchReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_publish_a_sourced_event(): void
    {
        $admin=User::factory()->create(['is_admin'=>true]);
        $leagueId=\DB::table('leagues')->insertGetId(['football_data_id'=>1,'name'=>'Premier League','created_at'=>now(),'updated_at'=>now()]);
        $home=Team::create(['football_data_id'=>101,'name'=>'Everton FC']);
        $away=Team::create(['football_data_id'=>102,'name'=>'Crystal Palace FC']);
        $match=FootballMatch::create([
            'football_data_id'=>5001,'league_id'=>$leagueId,'league_football_data_id'=>1,
            'home_team_id'=>$home->id,'home_team_football_data_id'=>101,'away_team_id'=>$away->id,'away_team_football_data_id'=>102,
            'match_date'=>now(),'status'=>'IN_PLAY','home_score'=>1,'away_score'=>0,
        ]);

        $this->actingAs($admin)->post(route('admin.matches.events.store',$match),[
            'team_id'=>$home->id,'type'=>'goal','minute'=>31,'player_name'=>'Ayo Blade',
            'assist_player_name'=>'Kofi Wing','source_url'=>'https://www.evertonfc.com/match-update',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('match_events',['match_id'=>$match->id,'type'=>'goal','minute'=>31,'player_name'=>'Ayo Blade']);
        $event=$match->events()->first();
        $this->assertSame('matchday-reporter',data_get($event->metadata,'provider'));
        $this->assertTrue(data_get($event->metadata,'verified'));
        $this->get(route('matches.chronicle',$match))->assertOk()->assertSee('VERIFIED REPORT',false)->assertSee('Ayo Blade',false);
    }

    public function test_reporter_rejects_a_team_outside_the_match(): void
    {
        $admin=User::factory()->create(['is_admin'=>true]);
        $leagueId=\DB::table('leagues')->insertGetId(['football_data_id'=>1,'name'=>'League','created_at'=>now(),'updated_at'=>now()]);
        $home=Team::create(['football_data_id'=>101,'name'=>'Home']);$away=Team::create(['football_data_id'=>102,'name'=>'Away']);$other=Team::create(['football_data_id'=>103,'name'=>'Other']);
        $match=FootballMatch::create(['football_data_id'=>5001,'league_id'=>$leagueId,'league_football_data_id'=>1,'home_team_id'=>$home->id,'home_team_football_data_id'=>101,'away_team_id'=>$away->id,'away_team_football_data_id'=>102,'match_date'=>now(),'status'=>'IN_PLAY']);

        $this->actingAs($admin)->post(route('admin.matches.events.store',$match),[
            'team_id'=>$other->id,'type'=>'goal','minute'=>20,'source_url'=>'https://example.com/source',
        ])->assertStatus(422);
        $this->assertDatabaseCount('match_events',0);
    }
}
