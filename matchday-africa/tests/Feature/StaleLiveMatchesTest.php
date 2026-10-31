<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Team;
use App\Models\User;
use App\Services\PredictionScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleLiveMatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_still_live_three_hours_after_kickoff_without_updates_are_closed(): void
    {
        $this->travelTo('2026-10-31 20:00:00');
        $stuck = $this->match(['match_date' => now('UTC')->subHours(4), 'last_api_update' => now()->subHours(2), 'home_score' => 1, 'away_score' => 1]);
        $neverUpdated = $this->match(['match_date' => now('UTC')->subDays(30), 'last_api_update' => null]);
        $stillPlaying = $this->match(['match_date' => now('UTC')->subMinutes(80), 'last_api_update' => now()->subMinutes(20)]);
        $extraTime = $this->match(['match_date' => now('UTC')->subMinutes(200), 'last_api_update' => now()->subMinutes(2)]);
        $feedStuck = $this->match(['match_date' => now('UTC')->subHours(7), 'last_api_update' => now()->subMinute()]);

        $this->artisan('matches:close-stale --dry-run')->expectsOutput('3 stale live matches would be closed.')->assertSuccessful();
        $this->assertSame('IN_PLAY', $stuck->fresh()->status);

        $this->artisan('matches:close-stale')->expectsOutput('3 stale live matches closed.')->assertSuccessful();

        foreach ([$stuck, $neverUpdated, $feedStuck] as $match) {
            $this->assertSame('FINISHED', $match->fresh()->status);
            $this->assertTrue($match->fresh()->statusInferred());
        }
        $this->assertSame(1, $stuck->fresh()->home_score);
        $this->assertSame('IN_PLAY', $stillPlaying->fresh()->status);
        $this->assertSame('IN_PLAY', $extraTime->fresh()->status);
    }

    public function test_inferred_results_are_not_scored_until_a_feed_confirms_them(): void
    {
        $match = $this->match(['match_date' => now('UTC')->subHours(5), 'last_api_update' => now()->subHours(3), 'home_score' => 2, 'away_score' => 0]);
        $user = User::factory()->create();
        app(\App\Services\MatchPredictionRecorder::class)->record($user, $match, 2, 0);
        app(\App\Services\StaleMatchCloser::class)->close();

        app(PredictionScoringService::class)->scoreAllPendingPredictions();
        $this->assertDatabaseHas('user_predictions', ['user_id' => $user->id, 'is_scored' => false]);

        $match->fresh()->forceFill(['last_api_update' => now()->addMinute()])->saveQuietly();
        app(PredictionScoringService::class)->scoreAllPendingPredictions();
        $this->assertDatabaseHas('user_predictions', ['user_id' => $user->id, 'is_scored' => true]);
    }

    private function match(array $attributes): FootballMatch
    {
        static $n = 0;
        $n++;
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 900 + $n, 'name' => 'League '.$n, 'created_at' => now(), 'updated_at' => now()]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create($attributes + ['football_data_id' => 8000 + $n, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Home '.$n])->id, 'away_team_id' => Team::create(['name' => 'Away '.$n])->id, 'status' => 'IN_PLAY']));
    }
}
