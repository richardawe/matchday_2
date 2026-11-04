<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\PredictionSet;
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

    public function test_score_calls_get_three_for_the_exact_score_and_one_for_the_result(): void
    {
        [$user, $set, $match] = $this->scenario();
        $exact = UserPrediction::create(['user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'score',
            'prediction_value' => '2-1', 'home_score_prediction' => 2, 'away_score_prediction' => 1, 'submitted_at' => now(), 'is_scored' => false, 'points_earned' => 0]);
        $other = User::factory()->create();
        $result = UserPrediction::create(['user_id' => $other->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'score',
            'prediction_value' => '3-0', 'home_score_prediction' => 3, 'away_score_prediction' => 0, 'submitted_at' => now(), 'is_scored' => false, 'points_earned' => 0]);

        app(PredictionScoringService::class)->scoreMatchPredictions($match);

        $this->assertSame(3, $exact->fresh()->points_earned);
        $this->assertSame(1, $result->fresh()->points_earned);
    }

    public function test_old_prediction_pages_lead_to_the_griot(): void
    {
        config(['griot_challenge.enabled' => true]);
        $this->get('/predictions')->assertRedirect(route('griot.challenge'));
        $this->get('/predictions/leaderboard')->assertRedirect(route('griot.challenge'));
        $this->get('/prediction-leagues/invite/ABC123')->assertRedirect(route('griot.challenge'));
        config(['griot_challenge.enabled' => false]);
        $this->get('/predictions')->assertRedirect(route('matches.index'));
        $this->actingAs(User::factory()->create())->get('/predictions/history')->assertOk();
    }

    public function test_stats_sum_all_points(): void
    {
        [$user, $set, $match] = $this->scenario();
        foreach ([[true, 3, '2-1'], [false, 2, '0-4']] as [$correct, $points, $value]) {
            UserPrediction::create([
                'user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id,
                'prediction_type' => $value === '2-1' ? 'score' : 'result', 'prediction_value' => $value,
                'is_correct' => $correct, 'is_scored' => true, 'points_earned' => $points, 'submitted_at' => now(),
            ]);
        }

        $stats = app(PredictionService::class)->getUserStats($user);

        $this->assertSame(2, $stats['total_predictions']);
        $this->assertSame(1, $stats['correct_predictions']);
        $this->assertSame(5, $stats['total_points']);
        $this->assertSame(1, $stats['rank']);
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
            'date_from' => '', 'date_to' => now()->toDateString(), 'is_correct' => '',
        ]);

        $this->assertSame(1, $history->total());
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
