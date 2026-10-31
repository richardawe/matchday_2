<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMetaTest extends TestCase
{
    use RefreshDatabase;

    private ?int $league = null;

    public function test_match_pages_have_unique_titles_descriptions_and_sports_event_data(): void
    {
        $finished = $this->match(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1, 'match_date' => '2026-10-25 15:00:00', 'football_data_id' => 1]);
        $upcoming = $this->match(['status' => 'SCHEDULED', 'match_date' => '2026-11-08 15:00:00', 'football_data_id' => 2, 'venue_name' => 'Emirates Stadium']);

        $page = $this->get(route('matches.show', $finished))->assertOk();
        $page->assertSee('<title>Arsenal 2–1 Chelsea: Premier League result, 25 Oct 2026 | Matchday Africa</title>', false)
            ->assertSee('Arsenal beat Chelsea 2–1 in the Premier League on 25 Oct 2026.', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:title"', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<link rel="canonical" href="'.route('matches.show', $finished).'">', false);
        $event = $this->jsonLd($page->getContent());
        $this->assertSame('SportsEvent', $event['@type']);
        $this->assertSame('Arsenal v Chelsea', $event['name']);
        $this->assertSame('2026-10-25T15:00:00+00:00', $event['startDate']);
        $this->assertSame('SportsTeam', $event['homeTeam']['@type']);

        $this->get(route('matches.show', $upcoming))->assertOk()
            ->assertSee('Arsenal v Chelsea prediction and live score, Premier League, Sun 8 Nov 2026', false)
            ->assertSee('16:00 WAT at Emirates Stadium', false);
    }

    public function test_team_and_article_pages_have_their_own_titles(): void
    {
        $team = Team::create(['name' => 'Enyimba FC', 'is_active' => true]);
        $blog = Blog::create(['title' => 'Osimhen returns', 'slug' => 'osimhen-returns', 'excerpt' => 'The striker is back in training.', 'content' => str_repeat('Story. ', 20), 'status' => 'published', 'published_at' => now()->subHour()]);

        $this->get(route('teams.show', $team))->assertOk()->assertSee('<title>Enyimba: fixtures, results and live scores | Matchday Africa</title>', false);
        $this->get(route('blogs.show', $blog))->assertOk()
            ->assertSee('<title>Osimhen returns | Matchday Africa</title>', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('The striker is back in training.');
    }

    public function test_plain_pages_get_their_own_canonical_and_section_titles(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('<title>Privacy policy | Matchday Africa</title>', false)
            ->assertSee('<link rel="canonical" href="'.url('/privacy').'">', false)
            ->assertSee('<meta property="og:image"', false);
        $this->get('/login')->assertOk()->assertSee('noindex', false)->assertSee('<meta property="og:title"', false);
    }

    private function jsonLd(string $html): array
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', true) ?? [];
    }

    private function match(array $attributes): FootballMatch
    {
        $league = $this->league ??= \DB::table('leagues')->insertGetId(['football_data_id' => 1, 'name' => 'Premier League', 'short_code' => 'PL', 'created_at' => now(), 'updated_at' => now()]);
        $team = fn ($name) => Team::firstOrCreate(['name' => $name])->id;

        return FootballMatch::withoutEvents(fn () => FootballMatch::create($attributes + ['league_id' => $league,
            'home_team_id' => $team('Arsenal FC'), 'away_team_id' => $team('Chelsea FC')]));
    }
}
