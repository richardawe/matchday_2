<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\GriotChallenge;
use App\Models\GriotChallengeWinner;
use App\Models\GrowthPost;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Models\UserPrediction;
use App\Services\BanterCardRenderer;
use App\Services\Griot\ChallengeLines;
use App\Services\Griot\GriotChallengeService;
use App\Services\Griot\GriotForecaster;
use App\Services\GuestPredictions;
use App\Services\MatchPredictionRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GriotChallengeTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\ChecksCaptions;

    private League $pl;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 3 Nov 2026, 10:00 Lagos (09:00 UTC).
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'UTC'));
        config(['griot_challenge.enabled' => true, 'griot_challenge.banter' => true]);
        $this->pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
    }

    public function test_nothing_shows_while_switched_off(): void
    {
        config(['griot_challenge.enabled' => false]);
        $this->get('/beat-the-griot')->assertNotFound();
        $this->artisan('griot:challenge')->assertSuccessful();
        $this->assertSame(0, GriotChallenge::count());
    }

    public function test_the_griot_picks_three_matches_on_different_days_and_calls_them(): void
    {
        $sat1 = $this->fixture('Arsenal FC', 'Chelsea FC', '2026-11-07 12:30:00');
        $sat2 = $this->fixture('Brentford FC', 'Fulham FC', '2026-11-07 15:00:00');
        $sun = $this->fixture('Liverpool FC', 'Manchester City FC', '2026-11-08 16:30:00');
        $mon = $this->fixture('Everton FC', 'Leeds United FC', '2026-11-09 20:00:00');
        $this->fixture('Wolverhampton Wanderers FC', 'Sunderland AFC', '2026-11-20 15:00:00'); // next round

        $this->artisan('griot:challenge')->assertSuccessful();
        $challenge = GriotChallenge::with('picks')->sole();
        $ids = $challenge->picks->pluck('match_id');

        $this->assertCount(3, $ids);
        $this->assertTrue($ids->contains($sat1->id), 'Arsenal v Chelsea, two big clubs, is in');
        $this->assertTrue($ids->contains($sun->id) && $ids->contains($mon->id), 'one match a day where possible');
        $this->assertFalse($ids->contains($sat2->id));
        $this->assertSame('2026-11-07 12:30:00', $challenge->first_kickoff->format('Y-m-d H:i:s'));

        // Planning again does nothing while the round is open.
        $this->artisan('griot:challenge')->assertSuccessful();
        $this->assertSame(1, GriotChallenge::count());
    }

    public function test_forecaster_takes_the_likeliest_result_then_its_likeliest_score(): void
    {
        $f = app(GriotForecaster::class);
        $this->assertSame([2, 1], $f->call(2.2, 1.2));
        $this->assertSame([1, 2], $f->call(1.2, 2.2));
        $this->assertSame([0, 0], $f->call(0.5, 0.5));

        $home = Team::create(['name' => 'Arsenal FC']);
        $away = Team::create(['name' => 'Chelsea FC']);
        $other = Team::create(['name' => 'Everton FC']);
        foreach (range(1, 5) as $i) {
            $this->fixture($home, $other, "2026-10-0{$i} 15:00:00", ['status' => 'FINISHED', 'home_score' => 3, 'away_score' => 0]);
            $this->fixture($other, $away, "2026-10-1{$i} 15:00:00", ['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1]);
        }
        $call = $f->forecast($this->fixture($home, $away, '2026-11-07 12:30:00'));
        $this->assertGreaterThan($call['away'], $call['home']);
        $this->assertSame('WWWWW', $call['facts']['home_form']);
        $this->assertSame(5, $call['facts']['home_wins']);
    }

    public function test_lines_quote_only_facts_that_back_the_call(): void
    {
        $challenge = $this->round();
        $pick = $challenge->picks->first();
        $pick->update(['home_pick' => 2, 'away_pick' => 1, 'facts' => ['home_form' => 'WWDWL', 'home_wins' => 3, 'away_form' => 'LLDWL', 'away_wins' => 1]]);
        $lines = app(ChallengeLines::class);
        $this->assertSame('Arsenal have won 3 of their last 5. I see 2–1 at home.', $lines->line($pick->fresh('match.homeTeam', 'match.awayTeam')));
        $this->assertStringContainsString('don win 3', $lines->line($pick->fresh('match.homeTeam', 'match.awayTeam'), 'pcm'));

        $pick->update(['facts' => ['home_form' => 'WL', 'home_wins' => 1]]);
        $this->assertSame('The home drums are loud. I see 2–1.', $lines->line($pick->fresh('match.homeTeam', 'match.awayTeam')));
    }

    public function test_visitors_and_fans_make_their_three_calls_on_the_page(): void
    {
        $challenge = $this->round();
        $calls = $challenge->picks->mapWithKeys(fn ($p) => [$p->match_id => ['home' => 2, 'away' => 0]])->all();

        $this->get('/beat-the-griot')->assertOk()->assertSee('Beat')->assertSee('the Griot')->assertSee('Lock my 3 calls')->assertSee('1GB of data');

        $this->post('/beat-the-griot', ['calls' => $calls])->assertRedirect('/beat-the-griot');
        $this->assertDatabaseCount('guest_predictions', 3);
        // One visitor, one token: all three calls are theirs.
        $this->assertSame(1, \App\Models\GuestPrediction::distinct()->count('guest_token'));

        $user = User::factory()->create();
        $this->actingAs($user)->post('/beat-the-griot', ['calls' => $calls])->assertRedirect('/beat-the-griot');
        $this->assertSame(3, UserPrediction::where('user_id', $user->id)->where('prediction_value', '2-0')->count());
        $this->actingAs($user)->get('/beat-the-griot')->assertSee('Update my calls');

        // A match that has kicked off takes no more calls.
        $first = $challenge->picks->first()->match;
        $this->travelTo(Carbon::parse($first->match_date->format('Y-m-d H:i:s'), 'UTC')->addMinute());
        $this->actingAs($user)->post('/beat-the-griot', ['calls' => [$first->id => ['home' => 5, 'away' => 5]]]);
        $this->assertSame('2-0', UserPrediction::where('user_id', $user->id)->where('match_id', $first->id)->value('prediction_value'));
    }

    public function test_home_and_match_pages_show_the_dare(): void
    {
        $challenge = $this->round();
        $this->get('/')->assertOk()->assertSee('Take on the Griot');
        $this->get(route('matches.show', $challenge->picks->first()->match))->assertOk()->assertSee('This match is in Beat the Griot');
    }

    public function test_scoring_finds_who_beat_the_griot_and_draws_ten_winners(): void
    {
        config(['griot_challenge.winners' => 10]);
        $challenge = $this->round();
        // The Griot calls 1–0 in each.
        $challenge->picks()->update(['home_pick' => 1, 'away_pick' => 0]);
        $recorder = app(MatchPredictionRecorder::class);
        $fans = User::factory()->count(14)->create();
        foreach ($challenge->picks as $pick) {
            foreach ($fans as $i => $fan) {
                // Twelve call 2–1 (right result + exact in the matches that end 2–1), two call 0–2.
                $recorder->record($fan, $pick->match, $i < 12 ? 2 : 0, $i < 12 ? 1 : 2);
            }
        }
        $late = User::factory()->create();

        $this->artisan('griot:challenge'); // nothing finished: still open
        $this->assertSame('open', $challenge->fresh()->status);

        foreach ($challenge->picks as $pick) {
            FootballMatch::withoutEvents(fn () => $pick->match->forceFill(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1])->save());
            // A call after kick-off doesn't count.
            UserPrediction::create(['user_id' => $late->id, 'prediction_set_id' => UserPrediction::value('prediction_set_id'), 'match_id' => $pick->match_id,
                'prediction_type' => 'score', 'prediction_value' => '2-1', 'home_score_prediction' => 2, 'away_score_prediction' => 1,
                'submitted_at' => Carbon::parse($pick->match->match_date->format('Y-m-d H:i:s'), 'UTC')->addMinute()->setTimezone(config('app.timezone')), 'points_earned' => 0, 'is_scored' => false]);
        }
        $this->travelTo(Carbon::parse('2026-11-09 23:00:00', 'UTC'));
        $this->artisan('griot:challenge')->assertSuccessful();

        $challenge->refresh();
        $this->assertSame('scored', $challenge->status);
        $this->assertSame(3, $challenge->griot_points); // right result three times
        $this->assertSame(14, $challenge->players);
        $this->assertSame(12, $challenge->beaters); // 9 points each
        $this->assertSame(10, $challenge->winners()->count());
        $this->assertSame(0, $challenge->winners()->whereIn('user_id', $fans->slice(12)->pluck('id')->push($late->id))->count());
        $winner = $challenge->winners()->first();
        $this->assertMatchesRegularExpression('/^GRIOT-[A-Z0-9]{5}$/', $winner->code);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $winner->user_id]);

        $this->actingAs($winner->user)->get('/beat-the-griot')->assertSee('You won 1GB of data!')->assertSee($winner->code)->assertSee('@matchdayafrica');
    }

    public function test_a_postponed_match_is_void(): void
    {
        $challenge = $this->round();
        [$a, $b, $c] = $challenge->picks->all();
        FootballMatch::withoutEvents(fn () => $a->match->forceFill(['status' => 'FINISHED', 'home_score' => 1, 'away_score' => 0])->save());
        FootballMatch::withoutEvents(fn () => $b->match->forceFill(['status' => 'FINISHED', 'home_score' => 1, 'away_score' => 0])->save());
        FootballMatch::withoutEvents(fn () => $c->match->forceFill(['status' => 'POSTPONED'])->save());

        $this->assertTrue(app(GriotChallengeService::class)->score($challenge->fresh()));
        $this->assertSame('scored', $challenge->fresh()->status);
    }

    public function test_points(): void
    {
        $this->assertSame(3, GriotChallengeService::points(2, 1, 2, 1));
        $this->assertSame(1, GriotChallengeService::points(3, 1, 2, 1));
        $this->assertSame(1, GriotChallengeService::points(0, 0, 2, 2));
        $this->assertSame(0, GriotChallengeService::points(1, 2, 2, 1));
    }

    public function test_admin_sees_who_called_and_messages_them(): void
    {
        $challenge = $this->round();
        $calls = $challenge->picks->mapWithKeys(fn ($p) => [$p->match_id => ['home' => 1, 'away' => 1]])->all();
        $kemi = User::factory()->create(['name' => 'Kemi Adeyemi', 'email' => 'kemi@example.com']);
        $tunde = User::factory()->create(['name' => 'Tunde Bakare']);
        $bystander = User::factory()->create(['name' => 'Not Playing']);
        $this->actingAs($kemi)->post('/beat-the-griot', ['calls' => $calls]);
        $this->actingAs($tunde)->post('/beat-the-griot', ['calls' => array_slice($calls, 0, 1, true)]);
        $this->app['auth']->forgetGuards();
        $this->post('/beat-the-griot', ['calls' => $calls]); // a guest
        $this->assertDatabaseCount('guest_predictions', 3);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/griot')->assertOk()
            ->assertSee('Kemi Adeyemi')->assertSee('kemi@example.com')->assertSee('3 of 3')
            ->assertSee('Tunde Bakare')->assertSee('1 of 3')->assertDontSee('Not Playing')
            ->assertSee('2 fans with an account', false)->assertSee('1 guest without an account', false)
            ->assertSee("Message this round's 2 fans", false);

        $this->actingAs($admin)->post(route('admin.griot.message.round', $challenge), [
            'title' => 'Calls close soon', 'body' => 'Lock in your last call before Saturday.',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(['Calls close soon'], $kemi->notifications()->get()->pluck('data.title')->all());
        $this->assertSame(1, $tunde->notifications()->count());
        $this->assertSame(0, $bystander->notifications()->count());
        $this->assertDatabaseHas('announcements', ['audience' => "griot:{$challenge->id}", 'recipients' => 2]);
        $this->assertSame(route('griot.challenge'), $kemi->notifications()->first()->data['url']);

        // Everyone who has ever played, from here or from /admin/messages.
        $this->actingAs($admin)->post(route('admin.griot.message'), ['title' => 'New round', 'body' => 'The Griot has spoken.'])->assertRedirect();
        $this->assertSame(2, $kemi->notifications()->count());
        $this->actingAs($admin)->post(route('admin.messages.store'), ['title' => 'Prizes', 'body' => 'Ten winners.', 'audience' => 'griot'])->assertRedirect();
        $this->assertSame(3, $tunde->notifications()->count());
        $this->assertSame(0, $bystander->notifications()->count());

        $this->actingAs($kemi)->post(route('admin.griot.message'), ['title' => 'x', 'body' => 'y'])->assertForbidden();
    }

    public function test_admin_records_a_credited_winner(): void
    {
        $challenge = $this->round();
        $fan = User::factory()->create(['name' => 'Kemi Adeyemi']);
        $winner = GriotChallengeWinner::create(['griot_challenge_id' => $challenge->id, 'user_id' => $fan->id, 'code' => 'GRIOT-AB123']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/griot')->assertOk()->assertSee('GRIOT-AB123')->assertSee('Mark credited');
        $this->actingAs($admin)->post(route('admin.griot.credit', $winner), ['phone' => '08030000000', 'network' => 'MTN'])->assertRedirect();
        $this->assertNotNull($winner->fresh()->credited_at);
        $this->assertSame($admin->id, $winner->fresh()->credited_by);
        $this->actingAs($admin)->post(route('admin.griot.credit', $winner), ['phone' => '1', 'network' => 'Vodafone'])->assertSessionHasErrors('network');
        $this->actingAs($fan)->get('/admin/griot')->assertForbidden();

        $this->assertSame('Kemi A.', GriotChallengeService::publicName($fan));
    }

    public function test_round_cards_and_social_posts(): void
    {
        config(['buffer.key' => 'key', 'buffer.channels' => ['x' => 'chan-x', 'instagram' => null, 'tiktok' => null], 'buffer.x.auto' => true]);
        $challenge = $this->round();
        $png = $this->get(route('griot.card', ['challenge' => $challenge, 'kind' => 'calls']))->assertOk();
        [$w, $h] = getimagesize($png->baseResponse->getFile()->getPathname());
        $this->assertSame([1080, 1080], [$w, $h]);
        $this->get(route('griot.card', ['challenge' => $challenge, 'kind' => 'verdict']))->assertNotFound();

        $this->artisan('twitter:engage');
        $post = GrowthPost::where('content_key', "griot:{$challenge->id}:calls")->sole();
        $this->assertStringStartsWith("The Griot has spoken\n", $post->message);
        $this->assertStringContainsString('Free to play:', $post->message);
        $this->assertStringContainsString('Arsenal', $post->message);
        $this->assertCaptionsHaveNoEmoji();
    }

    public function test_verdict_and_banter_captions_have_no_emoji(): void
    {
        config(['buffer.key' => 'key', 'buffer.channels' => ['x' => 'chan-x', 'instagram' => null, 'tiktok' => null], 'buffer.x.auto' => true]);
        $challenge = $this->round();
        $this->artisan('twitter:engage'); // the calls post

        // The round is played and scored: every match a home win. A banter post lapses a day after its
        // match, so by Monday night only the Monday match still gets one.
        foreach ($challenge->picks as $pick) {
            $pick->match->update(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 0]);
        }
        $this->travelTo(Carbon::parse('2026-11-09 22:00:00', 'UTC'));
        $challenge->update(['status' => 'scored', 'scored_at' => now(), 'griot_points' => 4, 'beaters' => 12]);
        $this->artisan('twitter:engage');

        $verdict = GrowthPost::where('content_key', "griot:{$challenge->id}:verdict")->sole();
        $this->assertStringStartsWith("12 fans beat the Griot\n", $verdict->message);
        $this->assertStringContainsString('Next three calls are up:', $verdict->message);
        $banter = GrowthPost::where('type', 'banter')->get();
        $this->assertNotEmpty($banter);
        $this->assertTrue($banter->every(fn ($p) => str_contains($p->message, 'Get the card for your status:')));
        $this->assertEmpty(array_diff(['banter', 'griot'], GrowthPost::pluck('type')->unique()->all()));
        $this->assertCaptionsHaveNoEmoji();
    }

    public function test_banter_cards_are_9_by_16_and_offered_at_full_time(): void
    {
        $match = $this->fixture('Liverpool FC', 'Manchester City FC', '2026-11-02 16:30:00', ['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 0]);
        $cards = app(BanterCardRenderer::class);
        $this->assertSame('lost', BanterCardRenderer::outcome($match, 'away'));
        $words = $cards->words($match, 'away', 'pcm');
        $this->assertSame('MANCHESTER CITY DON FALL!', $words['head']);
        $this->assertStringContainsString('Liverpool beat Manchester City 2–0', $words['line']);

        $res = $this->get(route('matches.banter', ['match' => $match, 'side' => 'away', 'voice' => 'pcm']))->assertOk();
        [$w, $h] = getimagesize($res->baseResponse->getFile()->getPathname());
        $this->assertSame([1080, 1920], [$w, $h]);
        $this->get(route('matches.show', $match))->assertOk()->assertSee('Send the banter')->assertSee('Roast Manchester City');

        $upcoming = $this->fixture('Arsenal FC', 'Chelsea FC', '2026-11-07 12:30:00');
        $this->get(route('matches.banter', ['match' => $upcoming, 'side' => 'home', 'voice' => 'en']))->assertNotFound();
        config(['griot_challenge.banter' => false]);
        $this->get(route('matches.show', $match))->assertOk()->assertDontSee('Send the banter');
    }

    private function round(): GriotChallenge
    {
        $this->fixture('Arsenal FC', 'Chelsea FC', '2026-11-07 12:30:00');
        $this->fixture('Liverpool FC', 'Manchester City FC', '2026-11-08 16:30:00');
        $this->fixture('Tottenham Hotspur FC', 'Manchester United FC', '2026-11-09 19:00:00');
        $this->assertNotNull(app(GriotChallengeService::class)->plan());

        return GriotChallenge::with('picks.match.homeTeam', 'picks.match.awayTeam')->sole();
    }

    private function fixture(Team|string $home, Team|string $away, string $utc, array $extra = []): FootballMatch
    {
        $team = fn ($t) => $t instanceof Team ? $t : Team::firstOrCreate(['name' => $t]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create($extra + [
            'league_id' => $this->pl->id, 'home_team_id' => $team($home)->id, 'away_team_id' => $team($away)->id,
            'match_date' => $utc, 'status' => 'TIMED',
        ]));
    }
}
