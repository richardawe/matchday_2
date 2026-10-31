<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReelPagesTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        File::delete($this->files);
        parent::tearDown();
    }

    public function test_a_reel_has_its_own_shareable_page_and_appears_in_the_index(): void
    {
        $match = $this->reelMatch();

        $this->get(route('reels.show', $match))->assertOk()
            ->assertSee('Arsenal 2–1 Chelsea')
            ->assertSee('<video', false);
        $this->get(route('reels.index'))->assertOk()->assertSee(route('reels.show', $match), false);
    }

    public function test_follow_links_appear_in_the_footer_and_after_the_reel(): void
    {
        $match = $this->reelMatch();

        $this->get(route('reels.show', $match))->assertOk()
            ->assertSee('https://x.com/matchdayafrica', false)
            ->assertSee('https://www.tiktok.com/@matchday_africa', false)
            ->assertSee('https://www.instagram.com/matchdaydotafrica/', false)
            ->assertSee('FOLLOW US');
        $this->get('/terms')->assertOk()->assertSee('@matchdaydotafrica');
    }

    public function test_a_match_without_a_reel_has_no_reel_page(): void
    {
        $match = $this->reelMatch(ready: false);

        $this->get(route('reels.show', $match))->assertNotFound();
    }

    private function reelMatch(bool $ready = true): FootballMatch
    {
        $pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $m = FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => Team::create(['name' => 'Arsenal FC'])->id,
            'away_team_id' => Team::create(['name' => 'Chelsea FC'])->id, 'match_date' => now('UTC')->subDay(),
            'status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1]);
        if ($ready) {
            $m->forceFill(['metadata' => ['reel' => ['status' => 'ready', 'source' => 'server', 'ready_at' => now()->toIso8601String()]]])->save();
            File::ensureDirectoryExists(storage_path('app/reels'));
            File::put($this->files[] = storage_path("app/reels/{$m->id}.mp4"), 'x');
            $this->files[] = public_path("generated/reels/{$m->id}/{$m->id}.mp4");
        }

        return $m;
    }
}
