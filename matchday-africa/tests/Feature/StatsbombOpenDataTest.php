<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;use Illuminate\Support\Facades\Http;use Tests\TestCase;
class StatsbombOpenDataTest extends TestCase{use RefreshDatabase;
 public function test_afcon_open_data_is_imported_and_attributed():void{Http::fake([
  '*matches/1267/107.json'=>Http::response([['match_id'=>99,'match_date'=>'2024-01-20','competition'=>['competition_name'=>'African Cup of Nations'],'season'=>['season_name'=>'2023'],'home_team'=>['home_team_name'=>'Nigeria'],'away_team'=>['away_team_name'=>'Ghana']]]),
  '*lineups/99.json'=>Http::response([['team_name'=>'Nigeria','lineup'=>[['player_id'=>7,'player_name'=>'Ayo Blade','player_nickname'=>null,'country'=>['name'=>'Nigeria'],'positions'=>[['from_period'=>1,'from'=>'00:00','to'=>'90:00']]]]],['team_name'=>'Ghana','lineup'=>[]]]),
  '*events/99.json'=>Http::response([['player'=>['id'=>7],'type'=>['name'=>'Shot'],'shot'=>['outcome'=>['name'=>'Goal']]],['player'=>['id'=>7],'type'=>['name'=>'Pass'],'pass'=>['goal_assist'=>true]]]),
 ]);$this->artisan('statsbomb:import-open',['--limit'=>1])->assertSuccessful();$this->assertDatabaseHas('statsbomb_player_matches',['statsbomb_match_id'=>99,'player_name'=>'Ayo Blade','goals'=>1,'assists'=>1,'minutes'=>90]);$this->get(route('discovery.history'))->assertOk()->assertSee('Ayo Blade')->assertSee('StatsBomb Open Data');}
}
