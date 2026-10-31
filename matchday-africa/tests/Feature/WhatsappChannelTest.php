<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_channel_cta_shows_on_home_and_match_pages_once_the_link_is_set(): void
    {
        config(['social.whatsapp_channel' => 'https://whatsapp.com/channel/TEST123']);
        $match = $this->match();

        $this->get('/')->assertOk()->assertSee('Join our WhatsApp channel')->assertSee('data-wa-channel="home"', false)->assertSee('https://whatsapp.com/channel/TEST123', false);
        $this->get(route('matches.show', $match))->assertOk()->assertSee('Join our WhatsApp channel');
    }

    public function test_the_channel_cta_stays_hidden_without_a_link(): void
    {
        config(['social.whatsapp_channel' => null]);

        $this->get('/')->assertOk()->assertDontSee('Join our WhatsApp channel');
    }

    private function match(): FootballMatch
    {
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create(['football_data_id' => 77, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now()->addDay(), 'status' => 'SCHEDULED']));
    }
}
