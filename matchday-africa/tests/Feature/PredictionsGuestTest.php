<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\PredictionSet;
use App\Models\PredictionSetMatch;
use App\Models\Team;
use App\Models\User;
use App\Models\UserPrediction;
use App\Services\GuestPredictions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PredictionsGuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_open_predictions_and_the_leaderboard(): void
    {
        $this->get(route('predictions.index'))->assertOk()->assertSee('No account needed');
        $this->get(route('predictions.leaderboard'))->assertOk();
        $this->get(route('predictions.history'))->assertRedirect(route('login'));
    }

    public function test_a_guest_prediction_on_a_round_is_kept_and_claimed_at_signup(): void
    {
        $match = $this->match();
        $owner = User::factory()->create();
        $set = PredictionSet::create([
            'name' => 'Weekend calls', 'admin_id' => $owner->id, 'status' => 'active', 'prediction_deadline' => now()->addDay(),
        ]);
        $item = PredictionSetMatch::create([
            'prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_type' => 'score', 'points_value' => 3,
        ]);

        $this->get(route('predictions.show', $set))->assertOk()->assertSee('Submit my predictions');

        $this->followingRedirects()->post(route('predictions.submit', $set), [
            'predictions' => [$item->id => ['match_id' => $match->id, 'prediction_type' => 'score', 'prediction_value' => '2-1']],
        ])->assertSee('Save your pick and join the league');

        $picks = session(GuestPredictions::ROUND_SESSION);
        $this->assertSame('2-1', $picks[0]['prediction_value']);

        $this->withSession([GuestPredictions::ROUND_SESSION => $picks])
            ->post('/register', ['name' => 'Kemi', 'email' => 'kemi-round@example.com', 'password' => 'password'])
            ->assertRedirect();

        $user = User::where('email', 'kemi-round@example.com')->firstOrFail();
        $this->assertDatabaseHas('user_predictions', [
            'user_id' => $user->id, 'prediction_set_id' => $set->id, 'match_id' => $match->id, 'prediction_value' => '2-1',
        ]);
        $this->assertSame(1, UserPrediction::where('user_id', $user->id)->count());
    }

    private function match(): FootballMatch
    {
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create([
            'football_data_id' => 77, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now()->addDay(), 'status' => 'SCHEDULED',
        ]));
    }
}
