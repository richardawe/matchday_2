<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\Team;
use App\Support\ShareLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_links_carry_utm_tags_and_keep_the_fragment(): void
    {
        $this->assertSame('https://matchday.africa/matches/4?utm_source=whatsapp&utm_medium=share&utm_campaign=prediction#prediction-arena',
            ShareLink::tagged('https://matchday.africa/matches/4#prediction-arena', 'whatsapp', 'prediction'));
        $this->assertSame('https://matchday.africa/x?a=1&utm_source=copy&utm_medium=share&utm_campaign=match',
            ShareLink::tagged('https://matchday.africa/x?a=1', 'copy', 'match'));

        $links = ShareLink::links('https://matchday.africa/reels/9', 'Watch it', 'reel');
        $this->assertStringStartsWith('https://wa.me/?text=', $links['whatsapp']);
        $this->assertStringContainsString(rawurlencode('utm_source=whatsapp'), $links['whatsapp']);
        $this->assertStringStartsWith('https://x.com/intent/post?', $links['x']);
        $this->assertStringContainsString(urlencode('utm_source=x'), $links['x']);
    }

    public function test_match_page_has_share_buttons_and_the_match_card_as_og_image(): void
    {
        $match = $this->match();

        $this->get(route('matches.show', $match))->assertOk()
            ->assertSee('data-share-network="whatsapp"', false)
            ->assertSee('data-share-network="x"', false)
            ->assertSee('data-share-network="copy"', false)
            ->assertSee('utm_campaign=match', false)
            ->assertSee('<meta property="og:image" content="'.e(route('matches.card', $match)), false);
    }

    public function test_article_page_has_share_buttons(): void
    {
        $blog = Blog::create(['title' => 'Osimhen returns', 'slug' => 'osimhen-returns', 'content' => str_repeat('Story. ', 20), 'status' => 'published', 'published_at' => now()]);

        $this->get(route('blogs.show', $blog))->assertOk()->assertSee('utm_campaign=article', false)->assertSee('data-share-network="copy"', false);
    }

    private function match(): FootballMatch
    {
        $league = \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);

        return FootballMatch::withoutEvents(fn () => FootballMatch::create(['football_data_id' => 77, 'league_id' => $league,
            'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id, 'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id,
            'match_date' => now()->addDay(), 'status' => 'SCHEDULED']));
    }
}
