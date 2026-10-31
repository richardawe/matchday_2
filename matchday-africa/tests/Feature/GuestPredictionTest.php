<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\PredictionGroup;
use App\Models\Team;
use App\Models\User;
use App\Models\UserPrediction;
use App\Services\GuestPredictions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestPredictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_predict_and_is_asked_to_save_the_pick(): void
    {
        $match = $this->match();

        $response = $this->post(route('matches.prediction.store', $match), ['home_score' => 2, 'away_score' => 1]);
        $response->assertRedirect(route('matches.show', $match).'#prediction-arena');
        $this->assertNotNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === GuestPredictions::COOKIE));
        $this->assertDatabaseHas('guest_predictions', ['match_id' => $match->id, 'home_score' => 2, 'away_score' => 1]);

        $token = \App\Models\GuestPrediction::value('guest_token');
        $this->withCookie(GuestPredictions::COOKIE, $token)->get(route('matches.show', $match))->assertOk()
            ->assertSee('Save your pick and join the league')
            ->assertSee('Create my free account');
    }

    public function test_signing_up_moves_the_guest_pick_onto_the_account_and_returns_to_the_match(): void
    {
        $match = $this->match();
        $token = (string) \Illuminate\Support\Str::uuid();
        $this->withCookie(GuestPredictions::COOKIE, $token)->post(route('matches.prediction.store', $match), ['home_score' => 3, 'away_score' => 0]);

        $this->withCookie(GuestPredictions::COOKIE, $token)->post('/register', [
            'name' => 'Kemi', 'email' => 'kemi@example.com', 'password' => 'password',
            'return' => route('matches.show', $match, false).'#prediction-arena',
        ])->assertRedirect(route('matches.show', $match).'#prediction-arena');

        $user = User::where('email', 'kemi@example.com')->firstOrFail();
        $this->assertDatabaseHas('user_predictions', ['user_id' => $user->id, 'match_id' => $match->id, 'prediction_value' => '3-0']);
        $this->assertDatabaseHas('guest_predictions', ['guest_token' => $token, 'claimed_by' => $user->id]);
    }

    public function test_an_existing_signed_in_pick_wins_over_the_guest_pick_at_login(): void
    {
        $match = $this->match();
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $this->actingAs($user)->post(route('matches.prediction.store', $match), ['home_score' => 1, 'away_score' => 1]);
        auth()->logout();
        $token = (string) \Illuminate\Support\Str::uuid();
        $this->withCookie(GuestPredictions::COOKIE, $token)->post(route('matches.prediction.store', $match), ['home_score' => 4, 'away_score' => 0]);

        $this->withCookie(GuestPredictions::COOKIE, $token)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertSame('1-1', UserPrediction::where('user_id', $user->id)->value('prediction_value'));
    }

    public function test_guests_cannot_predict_once_the_match_is_live(): void
    {
        $match = $this->match();
        $match->update(['status' => 'IN_PLAY']);

        $this->post(route('matches.prediction.store', $match), ['home_score' => 1, 'away_score' => 0])->assertStatus(422);
        $this->assertDatabaseCount('guest_predictions', 0);
    }

    public function test_league_invite_link_lets_a_visitor_sign_up_and_join(): void
    {
        $owner = User::factory()->create();
        $group = PredictionGroup::create(['owner_id' => $owner->id, 'name' => 'Lagos Ballers', 'code' => 'LAG123']);
        $group->members()->attach($owner->id);

        $this->get(route('groups.invite', 'lag123'))->assertOk()->assertSee('Join Lagos Ballers')->assertSee('Create my free account');
        $this->post('/register', ['name' => 'Tobi', 'email' => 'tobi@example.com', 'password' => 'password'])
            ->assertRedirect(route('groups.show', $group));

        $this->assertTrue($group->members()->where('email', 'tobi@example.com')->exists());
    }

    public function test_weekly_table_counts_only_this_weeks_matches(): void
    {
        $this->travelTo('2026-10-29 12:00:00'); // a Thursday
        $owner = User::factory()->create(['name' => 'Owner']);
        $group = PredictionGroup::create(['owner_id' => $owner->id, 'name' => 'Office', 'code' => 'OFF111']);
        $group->members()->attach($owner->id);
        $thisWeek = $this->match(['match_date' => '2026-10-27 19:00:00', 'status' => 'FINISHED', 'football_data_id' => 9001]);
        $lastWeek = $this->match(['match_date' => '2026-10-20 19:00:00', 'status' => 'FINISHED', 'football_data_id' => 9002]);
        foreach ([[$thisWeek, 3], [$lastWeek, 5]] as [$m, $points]) {
            UserPrediction::create(['user_id' => $owner->id, 'match_id' => $m->id, 'prediction_type' => 'score', 'prediction_value' => '1-0', 'points_earned' => $points, 'submitted_at' => now(),
                'prediction_set_id' => \App\Models\PredictionSet::create(['name' => 'S'.$m->id, 'admin_id' => $owner->id, 'status' => 'active', 'prediction_deadline' => now()])->id]);
        }

        $this->actingAs($owner)->get(route('groups.show', $group))->assertOk()->assertSee('3 pts')->assertSee(route('groups.invite', 'OFF111'), false);
        $this->actingAs($owner)->get(route('groups.show', ['group' => $group, 'period' => 'all']))->assertOk()->assertSee('8 pts');
    }

    private function match(array $attributes = []): FootballMatch
    {
        static $n = 0;
        $n++;
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 100 + $n, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create($attributes + [
            'football_data_id' => 5000 + $n, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Arsenal FC '.$n])->id, 'away_team_id' => Team::create(['name' => 'Everton FC '.$n])->id,
            'match_date' => now()->addDay(), 'status' => 'SCHEDULED',
        ]));
    }
}
