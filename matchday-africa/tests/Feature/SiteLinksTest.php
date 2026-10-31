<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_and_cookie_pages_exist_and_the_footer_links_to_them(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy policy');
        $this->get('/cookies')->assertOk()->assertSee('Cookie policy')->assertSee('md_attr');
        $this->get('/privacy-policy')->assertRedirect('/privacy');
        $this->get('/terms')->assertOk()
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('cookies').'"', false);
    }

    public function test_community_page_exists(): void
    {
        $this->get('/community')->assertOk()->assertSee('Start a league with friends');
    }

    public function test_sitemap_lists_pages_matches_reels_and_articles(): void
    {
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);
        $match = FootballMatch::withoutEvents(fn () => FootballMatch::create(['football_data_id' => 77, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Arsenal FC', 'is_active' => true])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now('UTC')->subDay(), 'status' => 'FINISHED', 'home_score' => 1, 'away_score' => 0,
            'metadata' => ['reel' => ['status' => 'ready', 'ready_at' => now()->toIso8601String()]]]));
        $blog = Blog::create(['title' => 'Osimhen returns', 'slug' => 'osimhen-returns', 'content' => str_repeat('Story. ', 20), 'status' => 'published', 'published_at' => now()->subHour()]);

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }
        foreach ([route('home'), route('community'), route('matches.show', $match), route('reels.show', $match), route('blogs.show', $blog)] as $url) {
            $this->assertContains($url, $locs);
        }
    }

    public function test_robots_txt_points_at_the_sitemap(): void
    {
        $this->assertStringContainsString('Sitemap: https://matchday.africa/sitemap.xml', file_get_contents(public_path('robots.txt')));
    }
}
