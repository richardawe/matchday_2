<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMiddleware;
use App\Models\AgentAuditLog;
use App\Models\ApiToken;
use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\RadioBulletin;
use App\Models\StoryVideo;
use App\Models\Team;
use App\Models\User;
use App\Services\Agent\AgentAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AgentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private string $token;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-15 18:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        config(['app.url' => 'https://matchday.africa', 'story.enabled' => true, 'slideshow.commons.enabled' => false,
            'social.queue_token' => 'bot-token-0123456789-abcdefghijklmnopqrstuv', 'radio.enabled' => true]);
        Http::preventStrayRequests();
        [$this->agent] = app(AgentAccess::class)->upsert('agent@matchday.africa', 'Matchday agent', 'correct-horse-battery');
        [, $this->token] = app(AgentAccess::class)->issueToken($this->agent);

        $pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $home = Team::create(['name' => 'Arsenal']);
        $away = Team::create(['name' => 'Fulham']);
        $this->match = FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'match_date' => '2026-11-15 15:00:00', 'status' => 'FINISHED', 'home_score' => 1, 'away_score' => 1, 'home_possession' => 55, 'away_possession' => 45]);
        MatchEvent::create(['football_data_id' => 1, 'match_id' => $this->match->id, 'team_id' => $away->id, 'type' => 'goal', 'minute' => 88, 'player_name' => 'Alex Iwobi']);
        Player::create(['team_id' => $away->id, 'name' => 'Alex Iwobi', 'nationality' => 'Nigeria', 'nationality_code' => 'NGA', 'is_active' => true]);
        Player::create(['team_id' => $home->id, 'name' => 'Declan Rice', 'nationality' => 'England', 'nationality_code' => 'ENG', 'is_active' => true]);
    }

    private function api(): self
    {
        return $this->withToken($this->token);
    }

    public function test_the_command_creates_the_agent_login_and_shows_the_token_once(): void
    {
        $this->artisan('agent:create', ['email' => 'helper@matchday.africa'])
            ->expectsOutputToContain('role agent')
            ->expectsOutputToContain('Password (shown once)')
            ->expectsOutputToContain('API token (shown once')
            ->assertSuccessful();
        $user = User::where('email', 'helper@matchday.africa')->firstOrFail();
        $this->assertTrue($user->isAgent());
        $this->assertFalse($user->isAdmin());
        $this->assertSame('Matchday agent', $user->name);
        // Only a hash is stored.
        $token = ApiToken::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(64, strlen($token->token_hash));
        $this->assertStringStartsWith('mda_', $token->prefix);

        // An existing member is never turned into an agent.
        User::factory()->create(['email' => 'fan@example.com']);
        $this->artisan('agent:create', ['email' => 'fan@example.com'])->assertFailed();
        $this->assertFalse(User::where('email', 'fan@example.com')->first()->isAgent());
    }

    public function test_the_agent_can_use_every_endpoint(): void
    {
        $this->api()->getJson('/api/agent/matches?status=ft&from=2026-11-15&to=2026-11-15&competition=PL')->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('matches.0.home', 'Arsenal')
            ->assertJsonPath('matches.0.events.0.minute', 88)
            ->assertJsonPath('matches.0.events.0.player', 'Alex Iwobi')
            ->assertJsonPath('matches.0.stats.possession.home', 55);
        $this->api()->getJson('/api/agent/matches?status=upcoming')->assertOk()->assertJsonPath('count', 0);
        $this->api()->getJson('/api/agent/matches?status=bogus')->assertStatus(422);

        $players = $this->api()->getJson('/api/agent/players?african=true')->assertOk()->json('players');
        $this->assertSame(['Alex Iwobi'], array_column($players, 'name'));
        $this->assertSame('Nigeria', $players[0]['african_label']);
        $this->api()->getJson('/api/agent/players?country=NGA&club=Fulham')->assertOk()->assertJsonPath('count', 1);

        $b = RadioBulletin::create(['slot' => '2026-11-15-18', 'title' => 'The evening update', 'status' => 'published', 'stage' => 'audio',
            'published_at' => now()->subMinutes(5), 'script' => "Hello.\n\nArsenal drew.\n\nBye.", 'duration_seconds' => 90,
            'facts' => [['key' => 'match:1', 'kind' => 'result', 'headline' => 'Arsenal v Fulham', 'source' => 'Matchday match centre']],
            'segments' => [['fact_keys' => ['match:1'], 'text' => 'Arsenal drew.']], 'audio_cues' => [['start' => 2.0, 'end' => 80.0]]]);
        $this->api()->getJson('/api/agent/radio?latest=true')->assertOk()
            ->assertJsonPath('editions.0.id', $b->id)
            ->assertJsonPath('editions.0.transcript', "Hello.\n\nArsenal drew.\n\nBye.")
            ->assertJsonPath('editions.0.running_order.0.stories.0.headline', 'Arsenal v Fulham');

        Blog::create(['title' => 'Published one', 'content' => 'x', 'status' => 'published', 'published_at' => now()->subDay()]);
        $this->api()->getJson('/api/agent/articles')->assertOk()->assertJsonPath('articles.0.title', 'Published one');
        $draft = $this->api()->postJson('/api/agent/articles', ['title' => 'Iwobi rescues Fulham', 'content' => '<p>Late goal.</p>', 'status' => 'published',
            'sources' => ['https://matchday.africa/matches/1']])->assertCreated()->json();
        $this->assertSame('draft', $draft['status']);
        $this->assertNull(Blog::find($draft['id'])->published_at);
        $this->assertSame('agent', Blog::find($draft['id'])->metadata['source']);

        \App\Models\GuestPrediction::create(['guest_token' => str_repeat('a', 40), 'match_id' => $this->match->id, 'home_score' => 1, 'away_score' => 1]);
        \App\Models\GuestPrediction::create(['guest_token' => str_repeat('b', 40), 'match_id' => $this->match->id, 'home_score' => 2, 'away_score' => 0]);
        $this->api()->getJson('/api/agent/predictions')->assertOk()
            ->assertJsonPath('matches.0.entries.guests', 2)
            ->assertJsonPath('matches.0.calls.home_win', 1)
            ->assertJsonPath('matches.0.result.exact', 1)
            ->assertJsonMissingPath('matches.0.entries.users');

        $render = $this->api()->postJson('/api/agent/render', ['type' => 'match_story', 'match_id' => $this->match->id, 'hook' => 'Iwobi at the death', 'lang' => 'fr'])
            ->assertStatus(202)->json();
        $this->assertMatchesRegularExpression('/^story:\d+$/', $render['id']);
        $story = StoryVideo::firstOrFail();
        $this->assertSame(['match_story', 'fr', 'Iwobi at the death', 'api', $this->agent->id], [$story->kind, $story->lang, $story->hook, $story->source, $story->requested_by]);
        $this->api()->getJson('/api/agent/render/'.$render['id'])->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('mp4_url', null);
        $this->api()->getJson('/api/agent/render/story:999')->assertNotFound();
        $this->api()->postJson('/api/agent/render', ['type' => 'radio_video', 'episode_id' => $b->id])->assertStatus(202);
        $this->api()->postJson('/api/agent/render', ['type' => 'match_story'])->assertStatus(422);

        $this->api()->getJson('/api/agent/social-queue')->assertOk()->assertJsonStructure(['generated_at', 'items']);
        $this->api()->postJson('/api/agent/social-queue/story:999/posted', ['channel' => 'tiktok'])->assertNotFound();

        $this->get('/?utm_source=tiktok&utm_medium=social&utm_campaign=story');
        $this->api()->getJson('/api/agent/analytics?days=7')->assertOk()
            ->assertJsonPath('days', 7)
            ->assertJsonPath('visits.total', 1)
            ->assertJsonPath('visits.by_source.0.value', 'tiktok')
            ->assertJsonPath('visits.by_campaign.0.value', 'story');
        $this->api()->getJson('/api/agent/analytics?days=3')->assertStatus(422);

        // Every call is in the audit log, with the filters it used and never the token.
        $log = AgentAuditLog::where('channel', 'api')->get();
        $this->assertGreaterThanOrEqual(15, $log->count());
        $this->assertTrue($log->every(fn ($row) => $row->user_id === $this->agent->id));
        $this->assertSame('ft', $log->first()->params['values']['status']);
        $this->assertStringNotContainsString($this->token, AgentAuditLog::all()->toJson());
    }

    public function test_the_api_refuses_missing_revoked_and_non_agent_tokens(): void
    {
        $this->getJson('/api/agent/matches')->assertUnauthorized();
        $this->withToken('mda_nope')->getJson('/api/agent/matches')->assertUnauthorized();
        // The posting bot's token is not an agent token, and the agent token is not the bot's.
        $this->withToken((string) config('social.queue_token'))->getJson('/api/agent/matches')->assertUnauthorized();
        $this->api()->getJson('/api/social-queue')->assertUnauthorized();

        $member = User::factory()->create();
        [, $memberToken] = ApiToken::issue($member);
        $this->withToken($memberToken)->getJson('/api/agent/matches')->assertUnauthorized();

        // A new token revokes the old one.
        [, $fresh] = app(AgentAccess::class)->issueToken($this->agent);
        $this->api()->getJson('/api/agent/matches')->assertUnauthorized();
        $this->withToken($fresh)->getJson('/api/agent/matches')->assertOk();
        $this->assertSame(5, AgentAuditLog::where('status', 401)->count());
    }

    public function test_the_api_is_rate_limited_per_token(): void
    {
        config(['agent.per_minute' => 3]);
        foreach (range(1, 3) as $i) {
            $this->api()->getJson('/api/agent/articles')->assertOk();
        }
        $this->api()->getJson('/api/agent/articles')->assertStatus(429);
    }

    public function test_the_agent_is_denied_every_admin_page_outside_its_list(): void
    {
        $blog = Blog::create(['title' => 'Live article', 'content' => 'x', 'status' => 'published', 'published_at' => now()->subDay()]);
        $this->actingAs($this->agent);
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (!str_starts_with($name, 'admin.') || in_array($name, AdminMiddleware::AGENT_ROUTES, true)) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', (string) $blog->id, $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->call($method, '/'.$uri);
                if (in_array($name, ['admin.index', 'admin.dashboard'], true)) {
                    $response->assertRedirect(route('admin.studio.index'));
                } else {
                    $this->assertSame(403, $response->getStatusCode(), "{$method} /{$uri} ({$name}) should be closed to the agent");
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(60, $checked);
        foreach (['admin.users.index', 'admin.commerce.index', 'admin.youtube.index', 'admin.agent.index', 'admin.blogs.destroy', 'admin.social-queue.undo'] as $closed) {
            $this->assertNotContains($closed, AdminMiddleware::AGENT_ROUTES);
        }
        // Published articles stay out of reach; drafts are allowed and anything saved stays a draft.
        $this->get(route('admin.blogs.edit', $blog))->assertForbidden();
        $this->put(route('admin.blogs.update', $blog), ['title' => 'Changed', 'content' => 'y', 'status' => 'published'])->assertForbidden();
        $this->assertSame('Live article', $blog->fresh()->title);
        $this->assertTrue(AgentAuditLog::where('channel', 'web')->where('status', 403)->exists());
    }

    public function test_the_agent_can_open_its_pages_and_render_from_the_content_studio(): void
    {
        $this->actingAs($this->agent);
        $this->get('/admin')->assertRedirect(route('admin.studio.index'));
        $this->get(route('admin.studio.index'))->assertOk()->assertSee('Content studio')->assertSee('Render story')->assertDontSee('Signups');
        $this->get(route('admin.social-queue.index'))->assertOk();
        $this->get(route('admin.blogs.index'))->assertOk();

        $this->post(route('admin.studio.render'), ['kind' => 'match_story', 'subject_id' => $this->match->id, 'lang' => 'en'])
            ->assertSessionHas('success');
        $story = StoryVideo::firstOrFail();
        $this->assertSame(['pending', 'admin'], [$story->status, $story->source]);

        $story->update(['status' => 'ready', 'generated_hook' => 'Late drama!', 'captions' => ['x' => 'old', 'tiktok' => 'old']]);
        $this->post(route('admin.studio.update', $story), ['hook' => 'Iwobi!', 'banter' => '', 'captions' => ['tiktok' => 'New TikTok caption', 'x' => 'old']])
            ->assertSessionHas('success');
        $story->refresh();
        $this->assertSame(['Iwobi!', 'pending', 'New TikTok caption', true], [$story->hook, $story->status, $story->captions['tiktok'], $story->captions_edited]);
        $this->assertTrue(AgentAuditLog::where('channel', 'web')->where('route', 'admin.studio.update')->exists());

        // Only unfinished matches are refused.
        $this->match->update(['status' => 'SCHEDULED']);
        $this->post(route('admin.studio.render'), ['kind' => 'match_story', 'subject_id' => $this->match->id])->assertSessionHas('error');
    }

    public function test_an_admin_creates_the_agent_and_sees_its_token_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $this->post(route('admin.agent.store'), ['name' => 'Matchday agent', 'email' => 'bot@matchday.africa', 'password' => 'a-long-password-123'])
            ->assertSessionHas('success');
        $agent = User::where('email', 'bot@matchday.africa')->firstOrFail();
        $this->assertTrue($agent->isAgent());

        $response = $this->post(route('admin.agent.token', $agent));
        $plain = session('agent_token');
        $this->assertStringStartsWith('mda_', $plain);
        $this->get(route('admin.agent.index'))->assertOk()->assertSee($plain);
        $this->get(route('admin.agent.index'))->assertOk()->assertDontSee($plain);
        $this->withToken($plain)->getJson('/api/agent/articles')->assertOk();

        // The agent logs in with email and password.
        auth()->logout();
        $this->post('/login', ['email' => 'bot@matchday.africa', 'password' => 'a-long-password-123']);
        $this->assertAuthenticatedAs($agent);
        $this->get(route('admin.studio.index'))->assertOk();
    }
}
