<?php

namespace Tests\Feature;

use App\Models\AgentAuditLog;
use App\Models\BreakingNews;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Services\Agent\AgentAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BreakingNewsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC')->setTimezone('Africa/Lagos'));
        config(['app.url' => 'https://matchday.africa', 'radio.enabled' => true]);
        Http::preventStrayRequests();
        [$this->agent] = app(AgentAccess::class)->upsert('agent@matchday.africa', 'Matchday agent', 'correct-horse-battery');
        [, $this->token] = app(AgentAccess::class)->issueToken($this->agent);
    }

    private function postItem(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/agent/breaking', $data + [
            'headline' => 'Osimhen signs a new deal', 'link_url' => 'https://matchday.africa/blogs/osimhen-deal',
        ]);
    }

    public function test_the_agent_posts_an_item_that_goes_live_at_once_and_expires_on_its_own(): void
    {
        $r = $this->postItem(['body' => 'The striker commits until 2030.', 'image_url' => 'https://upload.wikimedia.org/a.jpg',
            'image_credit' => 'Jane Doe / Wikimedia Commons / CC BY-SA 4.0'])->assertCreated();
        $r->assertJson(['status' => 'live', 'source' => 'agent', 'created_by' => 'Matchday agent', 'link_kind' => 'article']);
        $this->assertSame('2026-11-18T18:00:00Z', $r->json('expires_at')); // default 6 hours
        $this->assertSame($r->json('id'), BreakingNews::current()?->id);

        // Logged with its headline.
        $log = AgentAuditLog::where('route', 'api.agent.breaking.store')->firstOrFail();
        $this->assertSame(201, $log->status);
        $this->assertSame('Osimhen signs a new deal', $log->params['values']['headline']);

        // On the homepage, the match page and the listen pages.
        $this->get('/')->assertOk()->assertSee('md-breaking--banner', false)->assertSee('Osimhen signs a new deal')
            ->assertSee('The striker commits until 2030.')->assertSee('Photo: Jane Doe / Wikimedia Commons / CC BY-SA 4.0')->assertSee('Read more');
        $match = FootballMatch::create(['league_id' => League::create(['name' => 'Premier League', 'short_code' => 'PL', 'football_data_id' => 2021])->id,
            'home_team_id' => Team::create(['name' => 'Arsenal'])->id, 'away_team_id' => Team::create(['name' => 'Fulham'])->id,
            'match_date' => '2026-11-18 15:00:00', 'status' => 'SCHEDULED']);
        $this->get(route('matches.show', $match))->assertOk()->assertSee('md-breaking--strip', false)->assertSee('Osimhen signs a new deal');

        // Gone after 6 hours, with nothing to clear by hand.
        $this->travel(6)->hours();
        $this->travel(1)->minutes();
        $this->assertNull(BreakingNews::current());
        $this->get('/')->assertOk()->assertDontSee('Osimhen signs a new deal');
    }

    public function test_only_the_newest_live_item_shows_and_ending_it_brings_back_the_one_before(): void
    {
        $first = $this->postItem(['headline' => 'First headline', 'expires_in_hours' => 48])->json('id');
        $this->travel(1)->minutes();
        $second = $this->postItem(['headline' => 'Second headline', 'link_url' => 'https://www.bbc.co.uk/sport/football/1'])->json('id');
        $this->get('/')->assertSee('Second headline')->assertDontSee('First headline')->assertSee('Source: bbc.co.uk')
            ->assertSee('rel="noopener nofollow"', false);

        $this->withToken($this->token)->postJson("/api/agent/breaking/{$second}/end")->assertOk()->assertJson(['status' => 'ended', 'ended_by' => 'Matchday agent']);
        $this->assertSame($first, BreakingNews::current()->id);
        $list = $this->withToken($this->token)->getJson('/api/agent/breaking')->assertOk();
        $this->assertSame($first, $list->json('current.id'));
        $this->assertCount(2, $list->json('items'));
        $this->assertSame('2026-11-20T12:00:00Z', $list->json('current.expires_at'));
    }

    public function test_the_agent_edits_its_own_items_and_the_rules_are_enforced(): void
    {
        $id = $this->postItem()->json('id');
        $this->withToken($this->token)->patchJson("/api/agent/breaking/{$id}", ['headline' => 'Osimhen extends to 2030', 'expires_in_hours' => 2])
            ->assertOk()->assertJson(['headline' => 'Osimhen extends to 2030', 'expires_at' => '2026-11-18T14:00:00Z']);

        $this->postItem(['headline' => str_repeat('a', 91)])->assertStatus(422)->assertJsonValidationErrors('headline');
        $this->postItem(['body' => str_repeat('b', 281)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postItem(['expires_in_hours' => 49])->assertStatus(422)->assertJsonValidationErrors('expires_in_hours');
        $this->postItem(['image_url' => 'https://example.com/a.jpg'])->assertStatus(422)->assertJsonValidationErrors('image_credit');
        $this->postItem(['image_url' => 'http://example.com/a.jpg', 'image_credit' => 'x'])->assertStatus(422)->assertJsonValidationErrors('image_url');
        $this->postItem(['link_url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('link_url');
        // Sirens are stripped; banned phrasing is refused.
        $this->assertSame('Big news at the Emirates', $this->postItem(['headline' => '🚨 Big news at the Emirates 🚨'])->json('headline'));
        $this->postItem(['headline' => 'Arsenal quietly sign a striker'])->assertStatus(422)->assertJsonValidationErrors('headline');

        // An admin's item is read-only to the agent; an ended item cannot be edited.
        $admin = User::factory()->create(['is_admin' => true]);
        $theirs = BreakingNews::create(['headline' => 'Admin item', 'source' => 'admin', 'created_by' => $admin->id, 'expires_at' => now()->addHour()]);
        $this->withToken($this->token)->patchJson("/api/agent/breaking/{$theirs->id}", ['headline' => 'Changed'])->assertForbidden();
        $this->withToken($this->token)->postJson("/api/agent/breaking/{$theirs->id}/end")->assertForbidden();
        $this->withToken($this->token)->postJson("/api/agent/breaking/{$id}/end")->assertOk();
        $this->withToken($this->token)->patchJson("/api/agent/breaking/{$id}", ['headline' => 'Again'])->assertStatus(422);
        $this->withToken($this->token)->patchJson('/api/agent/breaking/9999', ['headline' => 'x'])->assertNotFound();
        $this->withoutToken()->postJson('/api/agent/breaking', ['headline' => 'No token'])->assertUnauthorized();
    }

    public function test_admins_see_who_posted_and_edit_and_end_any_item(): void
    {
        $id = $this->postItem()->json('id');
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Richard']);
        $this->actingAs($admin);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('On the site: Osimhen signs a new deal')->assertSee('agent API');
        $this->get(route('admin.breaking.index'))->assertOk()->assertSee('Matchday agent')->assertSee('On the site');

        $this->put(route('admin.breaking.update', $id), ['headline' => 'Osimhen signs until 2030', 'body' => '', 'link_url' => 'https://matchday.africa/blogs/osimhen-deal'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $item = BreakingNews::find($id);
        $this->assertSame(['Osimhen signs until 2030', $admin->id, null], [$item->headline, $item->updated_by, $item->body]);
        $this->post(route('admin.breaking.end', $id))->assertRedirect();
        $this->assertSame($admin->id, $item->fresh()->ended_by);
        $this->assertNull(BreakingNews::current());

        $this->post(route('admin.breaking.store'), ['headline' => 'Admin headline', 'expires_in_hours' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('admin', BreakingNews::current()->source);
        $this->get('/listen')->assertSee('Admin headline');

        // The agent's web login cannot open the admin page.
        $this->actingAs($this->agent)->get(route('admin.breaking.index'))->assertForbidden();
    }
}
