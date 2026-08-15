<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\PredictionLeaderboard;
use App\Models\PredictionSeason;
use App\Models\PredictionSet;
use App\Models\Team;
use App\Models\User;
use App\Models\UserPrediction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPredictionSeasonTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_season_management(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.predictions.season.index'))
            ->assertForbidden();
    }

    public function test_confirmation_phrase_is_required_before_reset(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.predictions.season.store'), [
            'name' => '2026/27', 'confirmation' => 'RESET', 'acknowledge_deletion' => '1',
        ])->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('prediction_seasons', 0);
    }

    public function test_admin_can_start_season_and_only_prediction_competition_data_is_reset(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $player = User::factory()->create();
        $leagueId = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'League', 'created_at' => now(), 'updated_at' => now()]);
        $home = Team::create(['football_data_id' => 101, 'name' => 'Home']);
        $away = Team::create(['football_data_id' => 102, 'name' => 'Away']);
        $match = FootballMatch::create([
            'football_data_id' => 5001, 'league_id' => $leagueId, 'league_football_data_id' => 1,
            'home_team_id' => $home->id, 'home_team_football_data_id' => 101,
            'away_team_id' => $away->id, 'away_team_football_data_id' => 102,
            'match_date' => now(), 'status' => 'SCHEDULED',
        ]);
        $set = PredictionSet::create(['name' => 'Old Week', 'admin_id' => $admin->id, 'status' => 'active', 'prediction_deadline' => now()->addDay()]);
        UserPrediction::create([
            'user_id' => $player->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id,
            'prediction_type' => 'result', 'prediction_value' => 'Home Win', 'submitted_at' => now(),
        ]);
        PredictionLeaderboard::create([
            'user_id' => $player->id, 'prediction_set_id' => $set->id, 'period' => 'all_time',
            'total_predictions' => 1, 'correct_predictions' => 0, 'total_points' => 0, 'accuracy_percentage' => 0,
        ]);

        $this->actingAs($admin)->post(route('admin.predictions.season.store'), [
            'name' => '2026/27', 'confirmation' => 'START NEW SEASON', 'acknowledge_deletion' => '1',
        ])->assertRedirect(route('admin.predictions.season.index'));

        $this->assertDatabaseCount('user_predictions', 0);
        $this->assertDatabaseCount('prediction_leaderboards', 0);
        $this->assertDatabaseHas('prediction_sets', ['id' => $set->id, 'status' => 'archived']);
        $this->assertDatabaseHas('prediction_seasons', [
            'name' => '2026/27', 'started_by' => $admin->id, 'is_active' => true,
            'cleared_predictions' => 1, 'cleared_leaderboard_entries' => 1, 'archived_prediction_sets' => 1,
        ]);
        $this->assertDatabaseHas('matches', ['id' => $match->id]);
        $this->assertDatabaseHas('users', ['id' => $player->id]);
    }
}
