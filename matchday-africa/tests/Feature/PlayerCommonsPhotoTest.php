<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Services\Agent\AgentAccess;
use App\Services\Slideshow\CommonsPhotos;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Commons photos for players: the agent's photo endpoints, the name search and the nightly fill. */
class PlayerCommonsPhotoTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Player $iwobi;

    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 18:00:00', 'UTC'));
        config(['app.url' => 'https://matchday.africa', 'slideshow.commons.enabled' => true, 'slideshow.commons.min_width' => 700]);
        Http::preventStrayRequests();
        [$agent] = app(AgentAccess::class)->upsert('agent@matchday.africa', 'Matchday agent', 'correct-horse-battery');
        [, $this->token] = app(AgentAccess::class)->issueToken($agent);
        $fulham = Team::create(['name' => 'Fulham']);
        $this->iwobi = Player::create(['team_id' => $fulham->id, 'name' => 'Alex Iwobi', 'nationality' => 'Nigeria', 'nationality_code' => 'NGA', 'is_active' => true]);
        $img = imagecreatetruecolor(1080, 1440);
        ob_start();
        imagejpeg($img);
        $this->jpeg = (string) ob_get_clean();
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        File::delete(glob(storage_path('app/slideshow-photos/*.jpg')) ?: []);
        File::delete(glob(storage_path('app/player-media/test-*')) ?: []);
        File::delete([storage_path('app/player-media/sadio-mane.json'), storage_path('app/player-media/sadio-mane.jpg')]);
        parent::tearDown();
    }

    /** Commons answers imageinfo for one file with this licence and width. */
    private function commons(string $licence, int $width = 2400): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => ['7' => ['title' => 'File:Alex Iwobi 2023.jpg', 'imageinfo' => [[
                'mime' => 'image/jpeg', 'width' => $width, 'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Alex_Iwobi_2023.jpg',
                'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Alex_Iwobi_2023.jpg/1200px-Alex_Iwobi_2023.jpg',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg',
                'extmetadata' => ['LicenseShortName' => ['value' => $licence], 'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0'],
                    'Artist' => ['value' => '<a href="//commons.wikimedia.org/wiki/User:Jane">Jane Photographer</a>']],
            ]]]]]]),
            'upload.wikimedia.org/*' => Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_commons_urls_are_read_and_everything_else_refused(): void
    {
        $this->assertSame('Alex Iwobi 2023.jpg', CommonsPhotos::fileFromUrl('https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg'));
        $this->assertSame('Alex Iwobi 2023.jpg', CommonsPhotos::fileFromUrl('https://commons.m.wikimedia.org/wiki/File:Alex%20Iwobi%202023.jpg'));
        $this->assertSame('Alex Iwobi 2023.jpg', CommonsPhotos::fileFromUrl('https://commons.wikimedia.org/w/index.php?title=File:Alex_Iwobi_2023.jpg&oldid=1'));
        $this->assertSame('Alex Iwobi 2023.jpg', CommonsPhotos::fileFromUrl('https://upload.wikimedia.org/wikipedia/commons/a/ab/Alex_Iwobi_2023.jpg'));
        $this->assertSame('Alex Iwobi 2023.jpg', CommonsPhotos::fileFromUrl('https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Alex_Iwobi_2023.jpg/800px-Alex_Iwobi_2023.jpg'));
        $this->assertNull(CommonsPhotos::fileFromUrl('http://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg')); // not https
        $this->assertNull(CommonsPhotos::fileFromUrl('https://en.wikipedia.org/wiki/File:Alex_Iwobi_2023.jpg')); // may be fair use
        $this->assertNull(CommonsPhotos::fileFromUrl('https://upload.wikimedia.org/wikipedia/en/a/ab/Alex_Iwobi.jpg'));
        $this->assertNull(CommonsPhotos::fileFromUrl('https://commons.wikimedia.org.evil.example/wiki/File:A.jpg'));
        $this->assertNull(CommonsPhotos::fileFromUrl('https://commons.wikimedia.org/wiki/File:Iwobi.svg'));
        $this->assertNull(CommonsPhotos::fileFromUrl('https://www.gettyimages.com/photo/iwobi.jpg'));
    }

    public function test_the_agent_attaches_a_free_commons_photo_and_gets_the_record_back(): void
    {
        $this->commons('CC BY-SA 4.0');
        $this->withToken($this->token)->postJson("/api/agent/players/{$this->iwobi->id}/photo", ['source_url' => 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg'])
            ->assertCreated()
            ->assertJsonPath('player_id', $this->iwobi->id)
            ->assertJsonPath('photo.licence', 'CC BY-SA 4.0')
            ->assertJsonPath('photo.licence_url', 'https://creativecommons.org/licenses/by-sa/4.0')
            ->assertJsonPath('photo.artist', 'Jane Photographer')
            ->assertJsonPath('photo.credit', 'Jane Photographer / Wikimedia Commons / CC BY-SA 4.0')
            ->assertJsonPath('photo.source_url', 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg')
            ->assertJsonPath('photo.url', 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Alex_Iwobi_2023.jpg');
        $stored = data_get($this->iwobi->fresh()->metadata, 'commons_photo');
        $this->assertSame('ok', $stored['status']);
        $this->assertFileExists(storage_path('app/'.$stored['path']));
        // The story planner now finds it in the library.
        $this->assertNotNull(app(CommonsPhotos::class)->forPlayer($this->iwobi->fresh()));
        // And the player can be found by name.
        $this->withToken($this->token)->getJson('/api/agent/players?q=iwobi')->assertOk()
            ->assertJsonPath('players.0.id', $this->iwobi->id);
        $this->withToken($this->token)->getJson('/api/agent/players?q=alex+iwobi')->assertOk()->assertJsonCount(1, 'players');
        $this->withToken($this->token)->getJson('/api/agent/players?q=salah')->assertOk()->assertJsonCount(0, 'players');
    }

    public function test_non_commons_and_non_free_files_are_refused(): void
    {
        $api = $this->withToken($this->token);
        $api->postJson("/api/agent/players/{$this->iwobi->id}/photo", ['source_url' => 'https://www.gettyimages.com/detail/iwobi.jpg'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Only Wikimedia Commons'));
        $api->postJson('/api/agent/players/999999/photo', ['source_url' => 'https://commons.wikimedia.org/wiki/File:A.jpg'])->assertNotFound();
        $api->postJson("/api/agent/players/{$this->iwobi->id}/photo", [])->assertStatus(422);

        $this->commons('CC BY-NC 2.0');
        $api->postJson("/api/agent/players/{$this->iwobi->id}/photo", ['source_url' => 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Licence not allowed: CC BY-NC 2.0'));
        $this->assertNull(data_get($this->iwobi->fresh()->metadata, 'commons_photo'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'upload.wikimedia.org'));
    }

    public function test_small_files_are_refused(): void
    {
        $this->commons('CC0', 400);
        $this->withToken($this->token)->postJson("/api/agent/players/{$this->iwobi->id}/photo", ['source_url' => 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '400 px'));
    }

    public function test_a_photo_can_be_attached_by_name_for_players_without_a_row(): void
    {
        $this->commons('Public domain');
        $this->withToken($this->token)->postJson('/api/agent/player-photos', ['name' => 'Sadio Mane', 'source_url' => 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg'])
            ->assertCreated()->assertJsonPath('player_id', null)->assertJsonPath('name', 'Sadio Mane')->assertJsonPath('photo.licence', 'Public domain');
        $this->assertSame('Public domain', app(CommonsPhotos::class)->forName('Sadio Mane')['licence'] ?? null);
        $this->withToken($this->token)->postJson('/api/agent/player-photos', ['name' => 'Mane', 'source_url' => 'https://commons.wikimedia.org/wiki/File:A.jpg'])
            ->assertStatus(422);
    }

    public function test_the_nightly_job_fills_missing_photos_for_players_in_upcoming_matches(): void
    {
        $pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $arsenal = Team::create(['name' => 'Arsenal']);
        FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => $arsenal->id, 'away_team_id' => $this->iwobi->team_id,
            'match_date' => '2026-11-17 15:00:00', 'status' => 'SCHEDULED']);
        $idle = Team::create(['name' => 'Burnley']);
        $notPlaying = Player::create(['team_id' => $idle->id, 'name' => 'Lyle Foster', 'nationality' => 'South Africa', 'is_active' => true]);
        Http::fake([
            'www.wikidata.org/*action=wbsearchentities*' => Http::response(['search' => [['id' => 'Q1', 'label' => 'Alex Iwobi']]]),
            'www.wikidata.org/*action=wbgetentities*' => Http::response(['entities' => [
                'Q1' => ['id' => 'Q1', 'claims' => ['P106' => [['mainsnak' => ['datavalue' => ['value' => ['id' => 'Q937857']]]]], 'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Alex Iwobi 2023.jpg']]]]]],
            ]]),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => ['7' => ['title' => 'File:Alex Iwobi 2023.jpg', 'imageinfo' => [[
                'mime' => 'image/jpeg', 'width' => 2400, 'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Alex_Iwobi_2023.jpg',
                'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Alex_Iwobi_2023.jpg/1200px-Alex_Iwobi_2023.jpg',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Alex_Iwobi_2023.jpg',
                'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY 2.0'], 'Artist' => ['value' => 'Jane Photographer']],
            ]]]]]]),
            'upload.wikimedia.org/*' => Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->artisan('players:commons-photos', ['--pause' => 0])->expectsOutputToContain('1 found of 1 looked up')->assertSuccessful();
        $this->assertSame('CC BY 2.0', data_get($this->iwobi->fresh()->metadata, 'commons_photo.licence'));
        $this->assertNull(data_get($notPlaying->fresh()->metadata, 'commons_photo'));
        // Already has a photo: not looked up again.
        $this->artisan('players:commons-photos', ['--pause' => 0])->expectsOutputToContain('0 found of 0 looked up')->assertSuccessful();

        config(['slideshow.commons.enabled' => false]);
        $this->artisan('players:commons-photos')->expectsOutputToContain('off')->assertSuccessful();
    }
}
