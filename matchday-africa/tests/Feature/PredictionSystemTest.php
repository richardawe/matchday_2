<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\PredictionSet;
use App\Models\PredictionSetMatch;
use App\Models\Team;
use App\Models\User;
use App\Models\UserPrediction;
use App\Services\PredictionScoringService;
use App\Services\PredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PredictionSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_points_and_first_goalscorer_are_scored(): void
    {
        [$user, $set, $match] = $this->scenario();
        PredictionSetMatch::create(['prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'goalscorer', 'points_value' => 4]);
        MatchEvent::create([
            'football_data_id' => 9001, 'match_id' => $match->id, 'match_football_data_id' => 5001,
            'team_id' => $match->home_team_id, 'team_football_data_id' => 101, 'type' => 'goal',
            'minute' => 12, 'sort_order' => 0, 'player_name' => 'Ada Striker',
        ]);
        $prediction = UserPrediction::create([
            'user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id,
            'prediction_type' => 'goalscorer', 'prediction_value' => '  ada   striker ', 'submitted_at' => now(),
        ]);

        $result = app(PredictionScoringService::class)->evaluatePredictionWithScoring($prediction, $match);

        $this->assertTrue($result['is_correct']);
        $this->assertSame(4, $result['points_earned']);
    }

    public function test_missing_goalscorer_event_is_not_marked_incorrect(): void
    {
        [$user, $set, $match] = $this->scenario();
        PredictionSetMatch::create(['prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'goalscorer', 'points_value' => 2]);
        $prediction = UserPrediction::create([
            'user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id,
            'prediction_type' => 'goalscorer', 'prediction_value' => 'Ada Striker', 'submitted_at' => now(),
        ]);

        $result = app(PredictionScoringService::class)->evaluatePredictionWithScoring($prediction, $match);

        $this->assertNull($result['is_correct']);
        $this->assertSame(0, $result['points_earned']);
    }

    public function test_missing_goalscorer_event_remains_retryable(): void
    {
        [$user, $set, $match] = $this->scenario();
        PredictionSetMatch::create(['prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'goalscorer', 'points_value' => 2]);
        $prediction = UserPrediction::create([
            'user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id,
            'prediction_type' => 'goalscorer', 'prediction_value' => 'Ada Striker', 'submitted_at' => now(),
        ]);

        app(PredictionScoringService::class)->scoreSinglePrediction($prediction, $match);

        $this->assertFalse($prediction->fresh()->is_scored);
        $this->assertNull($prediction->fresh()->is_correct);
    }

    public function test_leaderboard_stats_sum_all_points_without_leaking_filters(): void
    {
        [$user, $set, $match] = $this->scenario();
        foreach ([[true, 3, '2-1'], [false, 2, '0-4']] as [$correct, $points, $value]) {
            UserPrediction::create([
                'user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id,
                'prediction_type' => $value === '2-1' ? 'score' : 'result', 'prediction_value' => $value,
                'is_correct' => $correct, 'is_scored' => true, 'points_earned' => $points, 'submitted_at' => now(),
            ]);
        }

        $stats = app(PredictionService::class)->getUserStats($user, $set);

        $this->assertSame(2, $stats['total_predictions']);
        $this->assertSame(1, $stats['correct_predictions']);
        $this->assertSame(5, $stats['total_points']);
    }

    public function test_predictions_cannot_be_updated_after_deadline(): void
    {
        [$user, $set, $match] = $this->scenario();
        $set->update(['prediction_deadline' => now()->subMinute()]);

        $result = app(PredictionService::class)->updatePredictions($user, $set, [[
            'match_id' => $match->id, 'prediction_type' => 'result', 'prediction_value' => 'Home Win',
        ]]);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['submitted_count']);
    }

    public function test_history_ignores_blank_filters_and_includes_the_full_end_date(): void
    {
        [$user, $set, $match] = $this->scenario();
        UserPrediction::create([
            'user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id,
            'prediction_type' => 'result', 'prediction_value' => 'Home Win',
            'is_correct' => true, 'is_scored' => true, 'submitted_at' => now()->setTime(22, 30),
        ]);

        $history = app(PredictionService::class)->getUserPredictionHistory($user, [
            'prediction_set_id' => '', 'date_from' => '', 'date_to' => now()->toDateString(), 'is_correct' => '',
        ]);

        $this->assertSame(1, $history->total());
    }

    public function test_leaderboard_opens_without_filter_parameters(): void
    {
        [$user] = $this->scenario();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('predictions.leaderboard'))
            ->assertOk()
            ->assertSee('Global standings');
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        $leagueId = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'League', 'created_at' => now(), 'updated_at' => now()]);
        $home = Team::create(['football_data_id' => 101, 'name' => 'Home']);
        $away = Team::create(['football_data_id' => 102, 'name' => 'Away']);
        $match = FootballMatch::create([
            'football_data_id' => 5001, 'league_id' => $leagueId, 'league_football_data_id' => 1,
            'home_team_id' => $home->id, 'home_team_football_data_id' => 101,
            'away_team_id' => $away->id, 'away_team_football_data_id' => 102,
            'match_date' => now()->subHour(), 'status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1,
        ]);
        $set = PredictionSet::create([
            'name' => 'Week One', 'admin_id' => $user->id, 'status' => 'active', 'prediction_deadline' => now()->addHour(),
        ]);

        return [$user, $set, $match];
    }
}
