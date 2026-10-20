<?php

namespace Tests\Feature;

use App\Models\FootballMatch;
use App\Models\League;
use App\Models\NewsCandidate;
use App\Models\RadioBulletin;
use App\Models\Team;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\RadioAudio;
use App\Services\RadioFacts;
use App\Services\RadioScript;
use App\Services\RadioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class RadioTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 12:00:00');
        config(['radio.enabled' => true, 'radio.auto_publish' => false]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::delete($this->files);
        parent::tearDown();
    }

    public function test_feature_is_hidden_while_off_or_before_migration(): void
    {
        config(['radio.enabled' => false]);
        $this->get('/listen')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('id="matchday-radio"', false);
        config(['radio.enabled' => true]);
        Schema::drop('radio_bulletins');
        $this->get('/listen/1')->assertNotFound();
        $this->get('/listen/1/audio.mp3')->assertNotFound();
        $this->get('/?home=v2')->assertOk()->assertDontSee('id="matchday-radio"', false);
        $this->artisan('radio:edition')->assertSuccessful();
        $this->artisan('radio:work')->assertSuccessful();
    }

    public function test_schedule_has_three_lagos_editions_and_no_midnight_run(): void
    {
        $event = collect(Schedule::events())->firstWhere('description', 'radio-editions');
        $this->assertSame('0 6,12,18 * * *', $event->expression);
        $this->assertSame('Africa/Lagos', $event->timezone);
        foreach (['2026-10-20 06:00:00' => '2026-10-20-06', '2026-10-20 12:00:00' => '2026-10-20-12',
            '2026-10-20 18:00:00' => '2026-10-20-18', '2026-10-21 01:00:00' => '2026-10-20-18'] as $time => $slot) {
            $this->travelTo(\Carbon\Carbon::parse($time, 'Africa/Lagos'));
            $a = app(RadioService::class)->queue();
            $this->assertSame($slot, $a->slot);
            $this->assertSame($a->id, app(RadioService::class)->queue()->id);
        }
        $this->assertDatabaseCount('radio_bulletins', 3);
    }

    public function test_script_stage_stays_private_and_audio_requires_review(): void
    {
        $fact = $this->fact();
        $this->mock(RadioFacts::class, fn ($m) => $m->shouldReceive('collect')->once()->andReturn([$fact]));
        $segments = [['fact_keys' => [$fact['key']], 'text' => 'Arsenal won 2 to 1 against Chelsea in the Premier League.']];
        $this->mock(RadioScript::class, function ($m) use ($segments) {
            $m->shouldReceive('write')->once()->andReturn($segments);
            $m->shouldReceive('verify')->once();
        });
        $b = app(RadioService::class)->queue();
        $this->artisan('radio:work')->assertSuccessful();
        $this->assertSame('draft', $b->fresh()->status);
        $this->get(route('radio.show', $b))->assertNotFound();
        $this->get(route('radio.audio', $b))->assertNotFound();
        $this->assertStringNotContainsString('AI', $b->fresh()->script);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.radio.action', $b), ['action' => 'voice'])->assertRedirect();
        $audio = $this->file($b);
        $this->mock(RadioAudio::class, fn ($m) => $m->shouldReceive('render')->once()->andReturn($audio));
        $this->artisan('radio:work')->assertSuccessful();
        $this->assertSame('ready', $b->fresh()->status);
        $this->get('/listen/feed.xml')->assertDontSee(route('radio.audio', $b));
        $this->post(route('admin.radio.action', $b), ['action' => 'publish'])->assertRedirect();
        $this->assertSame('published', $b->fresh()->status);
        $this->get(route('radio.audio', $b))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_public_player_precedes_reels_and_disclosure_is_inside_transcript(): void
    {
        $b = $this->published();
        $html = $this->get('/?home=v2')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'id="reels-hero"'), strpos($html, 'id="matchday-radio"'));
        $this->assertStringContainsString('preload="none"', $html);
        $this->assertLessThan(strpos($html, config('radio.disclosure')), strpos($html, 'Transcript &amp; sources'));
        $this->get(route('radio.audio', ['bulletin' => $b, 'download' => true]))->assertDownload('matchday-'.$b->slot.'.mp3');
    }

    public function test_auto_mode_writes_checks_voices_and_publishes_once(): void
    {
        config(['radio.auto_publish' => true]);
        $this->mock(RadioFacts::class, fn ($m) => $m->shouldReceive('collect')->once()->andReturn([$this->fact()]));
        $segments = [['fact_keys' => ['match:1'], 'text' => 'Arsenal won 2 to 1 against Chelsea in the Premier League.']];
        $editor = Mockery::mock(OpenRouterService::class);
        $editor->shouldReceive('editFootballArticle')->twice()->andReturn(json_encode(['segments' => $segments]), '{"ok":true}');
        $this->app->instance(OpenRouterService::class, $editor);
        $b = app(RadioService::class)->queue();
        $audio = $this->file($b);
        $this->mock(RadioAudio::class, fn ($m) => $m->shouldReceive('render')->once()->andReturn($audio));
        $this->artisan('radio:work')->assertSuccessful();
        $this->assertSame('queued', $b->fresh()->status);
        $this->assertSame('audio', $b->fresh()->stage);
        $this->artisan('radio:work')->assertSuccessful();
        $this->assertSame('published', $b->fresh()->status);
        $this->artisan('radio:work')->assertSuccessful();
        $this->assertDatabaseCount('radio_bulletins', 1);
    }

    public function test_rss_uses_real_lengths_stable_guids_and_escapes_text(): void
    {
        $b = $this->published(['title' => 'News & results <today>']);
        $response = $this->get('/listen/feed.xml')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame(route('radio.show', $b), (string) $xml->channel->item->guid);
        $this->assertSame((string) $b->audio_bytes, (string) $xml->channel->item->enclosure['length']);
        $this->assertStringContainsString('News & results <today>', (string) $xml->channel->item->title);
    }

    public function test_failures_and_missing_files_keep_the_last_playable_edition(): void
    {
        $old = $this->published(['slot' => '2026-10-20-06']);
        $this->mock(RadioFacts::class, fn ($m) => $m->shouldReceive('collect')->andThrow(new \RuntimeException('Feed unavailable')));
        $new = app(RadioService::class)->queue();
        $this->artisan('radio:work')->assertFailed();
        $this->assertSame($old->id, RadioBulletin::latestPlayable()->id);
        $this->assertSame('failed', $new->fresh()->status);
        // A worker retry does not run immediately, and the maximum is enforced.
        $this->artisan('radio:work')->assertSuccessful();
        $new->update(['attempts' => 3, 'updated_at' => now()->subMinutes(10)]);
        $this->artisan('radio:work')->assertSuccessful();
        $this->assertSame(3, $new->fresh()->attempts);
        $new->update(['status' => 'published', 'published_at' => now(), 'audio_path' => 'radio/999/a.mp3', 'audio_bytes' => 20]);
        $this->assertSame($old->id, RadioBulletin::latestPlayable()->id);
    }

    public function test_worker_does_not_overlap_an_admin_or_other_worker(): void
    {
        app(RadioService::class)->queue();
        $lock = Cache::lock('radio-pipeline', 100);
        $lock->get();
        try {
            $this->assertSame('Another radio worker is running.', app(RadioService::class)->work());
            $this->assertSame('queued', RadioBulletin::first()->status);
        } finally {
            $lock->release();
        }
    }

    public function test_evening_edition_is_current_overnight_and_delayed_only_after_morning_slot(): void
    {
        $b = new RadioBulletin(['slot' => '2026-10-20-18']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 02:00', 'Africa/Lagos'));
        $this->assertFalse($b->is_delayed);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 06:19', 'Africa/Lagos'));
        $this->assertFalse($b->is_delayed);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 06:21', 'Africa/Lagos'));
        $this->assertTrue($b->is_delayed);
    }

    public function test_unknown_facts_and_invented_numbers_are_rejected_before_ai_review(): void
    {
        $service = app(RadioScript::class);
        foreach ([['fact_keys' => ['unknown'], 'text' => 'Arsenal won 2 to 1 today.'],
            ['fact_keys' => ['match:1'], 'text' => 'Arsenal won 9 to 1 today.']] as $segment) {
            try {
                $service->validate([$segment], [$this->fact()]);
                $this->fail('An unsupported claim was accepted.');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_news_requires_attribution_rumour_labels_and_original_wording(): void
    {
        $fact = ['key' => 'news:a', 'kind' => 'news', 'source' => 'Club desk', 'rumour' => true,
            'text' => 'The club may make a bid and discussions could begin over the next few weeks.'];
        $this->expectException(\RuntimeException::class);
        app(RadioScript::class)->validate([['fact_keys' => ['news:a'], 'text' => 'Club desk says the club has completed the signing.']], [$fact]);
    }

    public function test_factual_checker_fails_closed(): void
    {
        $editor = Mockery::mock(OpenRouterService::class);
        $editor->shouldReceive('editFootballArticle')->once()->andReturn('{"ok":false}');
        $this->app->instance(OpenRouterService::class, $editor);
        $this->expectException(\RuntimeException::class);
        app(RadioScript::class)->verify([['fact_keys' => ['match:1'], 'text' => 'An invented player scored.']], [$this->fact()]);
    }

    public function test_radio_requests_json_mode_without_changing_article_output(): void
    {
        config(['services.openrouter.api_key' => 'test-key', 'services.openrouter.model' => 'test/model',
            'services.openrouter.fallback_models' => []]);
        Http::fake(['*' => Http::response(['model' => 'test/model', 'choices' => [['message' => ['content' => '{"ok":true}']]]])]);
        $editor = new OpenRouterService;
        $this->assertSame('{"ok":true}', $editor->editFootballArticle('Return JSON', true));
        Http::assertSent(fn ($r) => $r['response_format']['type'] === 'json_object' && $r['max_tokens'] === 3500);
        $editor->editFootballArticle('Write an article');
        Http::assertSent(fn ($r) => ! isset($r['response_format']) && $r['max_tokens'] === 1800);
    }

    public function test_factual_checker_accepts_only_true_json_even_when_markdown_fenced(): void
    {
        $editor = Mockery::mock(OpenRouterService::class);
        $editor->shouldReceive('editFootballArticle')->once()->andReturn("```json\n{\"ok\":true}\n```");
        $this->app->instance(OpenRouterService::class, $editor);
        app(RadioScript::class)->verify([['fact_keys' => ['match:1'], 'text' => $this->fact()['text']]], [$this->fact()]);
        $this->addToAssertionCount(1);
    }

    public function test_rejected_script_and_its_facts_are_retained_but_not_published(): void
    {
        $this->mock(RadioFacts::class, fn ($m) => $m->shouldReceive('collect')->once()->andReturn([$this->fact()]));
        $editor = Mockery::mock(OpenRouterService::class);
        $editor->shouldReceive('editFootballArticle')->twice()->andReturn(
            json_encode(['segments' => [['fact_keys' => ['match:1'], 'text' => 'Arsenal won 2 to 1 against Chelsea.']]]),
            '{"ok":false,"reason":"Unsupported player name"}'
        );
        $this->app->instance(OpenRouterService::class, $editor);
        $b = app(RadioService::class)->queue();
        $this->artisan('radio:work')->assertFailed();
        $this->assertSame('failed', $b->fresh()->status);
        $this->assertNotEmpty($b->fresh()->script);
        $this->assertSame([$this->fact()], $b->fresh()->facts);
        $this->assertStringContainsString('Unsupported player name', $b->fresh()->failure_reason);
        $this->get(route('radio.show', $b))->assertNotFound();
    }

    public function test_no_news_feed_is_fetched_until_approved_and_radio_coverage_is_separate(): void
    {
        config(['radio.news_sources' => []]);
        $this->assertSame([], app(RadioFacts::class)->collect());
        Http::assertNothingSent();
        config(['radio.news_sources' => ['BBC Sport']]);
        Http::fake(['*' => Http::response('<rss><channel/></rss>')]);
        $candidate = NewsCandidate::create(['source' => 'BBC Sport', 'source_url' => 'https://example.test/a',
            'title' => 'Ghana name their squad', 'summary' => 'Ghana have announced a football squad.',
            'fingerprint' => str_repeat('a', 64), 'selection_score' => 120, 'status' => 'published', 'source_published_at' => now()]);
        $first = app(RadioFacts::class)->collect();
        $this->assertCount(1, $first);
        $this->published(['facts' => $first]);
        $this->assertSame([], app(RadioFacts::class)->collect());
        $this->assertSame('published', $candidate->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_fixture_snapshot_uses_utc_wall_clock_and_lagos_display(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-20 12:00:00', 'Africa/Lagos'));
        $league = League::create(['name' => 'Premier League', 'short_code' => 'PL']);
        $home = Team::create(['name' => 'Arsenal']);
        $away = Team::create(['name' => 'Chelsea']);
        FootballMatch::create(['league_id' => $league->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'match_date' => '2026-10-20 11:30:00', 'status' => 'TIMED']);
        $facts = app(RadioFacts::class)->collect();
        $this->assertCount(1, $facts);
        $this->assertStringContainsString('12:30 WAT', $facts[0]['text']);
    }

    public function test_admin_access_and_withdrawal(): void
    {
        $b = $this->published();
        $this->get('/admin/radio')->assertRedirect('/login');
        $user = User::factory()->create(['is_admin' => false, 'role' => 'user']);
        $this->actingAs($user)->get('/admin/radio')->assertForbidden();
        $this->actingAs($user)->post(route('admin.radio.action', $b), ['action' => 'hold'])->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/radio')->assertOk();
        $this->post(route('admin.radio.action', $b), ['action' => 'hold'])->assertRedirect();
        $this->get(route('radio.audio', $b))->assertNotFound();
        $this->get('/listen/feed.xml')->assertDontSee(route('radio.audio', $b));
    }

    public function test_old_ready_audio_cannot_replace_a_newer_or_stale_edition(): void
    {
        $this->published();
        $b = RadioBulletin::create(['slot' => '2026-10-20-06', 'title' => 'Morning', 'status' => 'ready', 'recorded_at' => now()]);
        $b->update($this->file($b));
        $this->expectException(\RuntimeException::class);
        app(RadioService::class)->publish($b);
    }

    private function fact(): array
    {
        return ['key' => 'match:1', 'kind' => 'result', 'headline' => 'Arsenal v Chelsea',
            'text' => 'Arsenal 2, Chelsea 1. Full time in the Premier League.', 'source' => 'Matchday match centre',
            'url' => 'https://example.test/match/1', 'rumour' => false];
    }

    private function file(RadioBulletin $b): array
    {
        $relative = 'radio/'.$b->id.'/'.\Illuminate\Support\Str::uuid().'.mp3';
        $file = storage_path('app/'.$relative);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, str_repeat('x', 2048));
        $this->files[] = $file;

        return ['audio_path' => $relative, 'audio_bytes' => 2048, 'duration_seconds' => 240, 'voice' => 'bm_george'];
    }

    private function published(array $values = []): RadioBulletin
    {
        $b = RadioBulletin::create(array_merge(['slot' => '2026-10-20-12', 'title' => 'The midday update',
            'status' => 'published', 'stage' => 'audio', 'recorded_at' => now(), 'published_at' => now(),
            'facts' => [$this->fact()], 'segments' => [['fact_keys' => ['match:1'], 'text' => $this->fact()['text']]],
            'script' => 'Welcome to Matchday Africa. Arsenal won 2 to 1.'], $values));
        $b->update($this->file($b));

        return $b;
    }
}
