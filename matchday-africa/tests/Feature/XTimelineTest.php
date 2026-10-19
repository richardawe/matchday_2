<?php
namespace Tests\Feature;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\GrowthPost;
use App\Models\League;
use App\Models\Team;
use App\Models\User;
use App\Services\BufferClient;
use App\Support\XText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesBuffer;
use Tests\TestCase;

class XTimelineTest extends TestCase
{
    use RefreshDatabase, FakesBuffer;

    private FootballMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        // Saturday 09:00 in Lagos (08:00 UTC).
        $this->travelTo(now('Africa/Lagos')->setDate(2026, 10, 24)->setTime(9, 0));
        config(['buffer.key' => 'key', 'buffer.channels' => ['x' => 'chan-x', 'instagram' => null, 'tiktok' => null], 'buffer.x.auto' => true]);

        $pl = League::create(['football_data_id' => 2021, 'short_code' => 'PL', 'name' => 'Premier League']);
        $minor = League::create(['football_data_id' => 9999, 'short_code' => 'XX9', 'name' => 'Unlisted League']);
        $team = fn ($name) => Team::create(['name' => $name])->id;
        // match_date holds UTC wall-clock: 14:00 UTC is 15:00 in Lagos.
        $this->match = FootballMatch::create(['league_id' => $pl->id, 'home_team_id' => $team('Arsenal FC'), 'away_team_id' => $team('Chelsea FC'),
            'match_date' => '2026-10-24 14:00:00', 'status' => 'TIMED']);
        FootballMatch::create(['league_id' => $minor->id, 'home_team_id' => $team('Alpha FC'), 'away_team_id' => $team('Beta FC'),
            'match_date' => '2026-10-24 13:00:00', 'status' => 'TIMED']);
        Blog::create(['title' => 'Osimhen fit for the weekend', 'slug' => 'osimhen-fit', 'excerpt' => 'The striker trained fully on Friday.',
            'content' => 'Body', 'status' => 'published', 'published_at' => now()->subHour(), 'featured_image' => 'https://publisher.example/cover.jpg']);
    }

    public function test_planner_writes_news_preview_and_fixtures_once(): void
    {
        $this->artisan('twitter:engage')->expectsOutput('3 posts planned.');
        $this->artisan('twitter:engage')->expectsOutput('0 posts planned.');

        $posts = GrowthPost::all()->keyBy('type');
        $this->assertSame(['fixtures', 'news', 'preview'], $posts->keys()->sort()->values()->all());
        $this->assertTrue($posts->every(fn ($p) => $p->status === 'queued' && XText::length($p->message) <= 280));

        $preview = $posts['preview'];
        $this->assertStringContainsString("Arsenal v Chelsea\nPremier League · Kick-off 15:00 WAT", $preview->message);
        $this->assertStringContainsString('#PremierLeague #MatchdayAfrica', $preview->message);
        $this->assertSame(route('matches.card', $this->match), $preview->image_url);
        // Three hours before kick-off, in app time; it lapses ten minutes before kick-off.
        $this->assertSame('2026-10-24 12:00', $preview->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-24 14:50', $preview->expires_at->format('Y-m-d H:i'));

        $this->assertStringContainsString('15:00  Arsenal v Chelsea', $posts['fixtures']->message);
        $this->assertStringNotContainsString('Alpha', $posts['fixtures']->message);
        // A publisher's cover photo is never re-uploaded; X shows the article's link card.
        $this->assertNull($posts['news']->image_url);
        $this->assertStringContainsString('Osimhen fit for the weekend', $posts['news']->message);
    }

    public function test_each_account_posts_once_per_slot_best_post_first(): void
    {
        $this->artisan('twitter:engage');
        $this->fakeBuffer(['post' => ['id' => 'x1']], ['post' => ['id' => 'x2']], ['post' => ['id' => 'x3']]);
        Http::fake([route('matches.card', $this->match) => Http::response('png', 200, ['Content-Type' => 'image/png'])]);

        // 09:00, morning slot: the fixtures list beats the article; then the slot is used.
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');
        $this->travel(1)->hours();
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');
        // 13:00: the preview (due since 12:00) with its checked card.
        $this->travelTo(now('Africa/Lagos')->setTime(13, 0));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');
        // 19:00: the article, the day's third and last post.
        $this->travelTo(now('Africa/Lagos')->setTime(19, 0));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');
        $this->travel(4)->hours();
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');

        $this->assertSame(['fixtures', 'preview', 'news'], GrowthPost::where('status', 'sent')->orderBy('published_at')->pluck('type')->all());
        $posts = $this->bufferPosts();
        $this->assertSame(['chan-x', 'chan-x', 'chan-x'], array_column($posts, 'channelId'));
        $this->assertSame(['shareNow', 'shareNow', 'shareNow'], array_column($posts, 'mode'));
        $this->assertSame([], $posts[0]['assets']);
        $this->assertSame(route('matches.card', $this->match), $posts[1]['assets'][0]['image']['url']);
    }

    public function test_instagram_gets_the_carded_posts_with_its_post_type(): void
    {
        config(['buffer.channels.instagram' => 'chan-ig']);
        $this->artisan('twitter:engage')->expectsOutput('4 posts planned.');
        $this->assertSame(['preview'], GrowthPost::where('channel', 'instagram')->pluck('type')->all());

        $this->fakeBuffer();
        Http::fake([route('matches.card', $this->match) => Http::response('png', 200, ['Content-Type' => 'image/png'])]);
        $this->travelTo(now('Africa/Lagos')->setTime(13, 0));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 2 sent, 0 failed');

        $ig = collect($this->bufferPosts())->firstWhere('channelId', 'chan-ig');
        $this->assertSame(['instagram' => ['type' => 'post', 'shouldShareToFeed' => true]], $ig['metadata']);
        $this->assertSame(route('matches.card', $this->match), $ig['assets'][0]['image']['url']);
    }

    public function test_posts_without_a_free_slot_in_time_expire(): void
    {
        config(['buffer.slots' => ['08:00']]);
        $this->artisan('twitter:engage');
        $this->fakeBuffer();

        $this->artisan('buffer:publish')->expectsOutput('Buffer: 1 sent, 0 failed');
        $this->travelTo(now('Africa/Lagos')->setTime(15, 0));
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');

        $this->assertSame('expired', GrowthPost::where('type', 'preview')->value('status'));
        $this->assertCount(1, $this->bufferPosts());
    }

    public function test_failed_post_is_retried_then_left_for_the_admin(): void
    {
        $this->artisan('twitter:engage');
        GrowthPost::where('type', '!=', 'news')->delete();
        $this->fakeBuffer(['message' => 'X channel needs reconnecting']);

        foreach (range(1, 3) as $run) {
            $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 1 failed');
        }
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');

        $post = GrowthPost::first();
        $this->assertSame(['failed', 3, 'X channel needs reconnecting'], [$post->status, $post->attempts, $post->last_error]);
    }

    public function test_nothing_is_queued_or_sent_while_autopost_is_off(): void
    {
        config(['buffer.x.auto' => false]);
        $this->fakeBuffer();

        $this->artisan('twitter:engage')->expectsOutput('3 posts planned.');
        $this->artisan('buffer:publish')->expectsOutput('Buffer: 0 sent, 0 failed');

        $this->assertSame(['draft'], GrowthPost::pluck('status')->unique()->values()->all());
        $this->assertSame([], $this->bufferPosts());
    }

    public function test_drafts_planned_while_off_are_queued_once_posting_is_on(): void
    {
        config(['buffer.x.auto' => false]);
        $this->artisan('twitter:engage');
        config(['buffer.x.auto' => true]);

        $this->artisan('twitter:engage')->expectsOutput('3 posts planned.');
        $this->assertSame(['queued'], GrowthPost::pluck('status')->unique()->values()->all());
    }

    public function test_results_round_up_goes_out_at_night(): void
    {
        $this->match->update(['status' => 'FINISHED', 'home_score' => 2, 'away_score' => 1]);
        $this->travelTo(now('Africa/Lagos')->setTime(22, 50));

        $this->artisan('twitter:engage');

        $results = GrowthPost::where('type', 'results')->first();
        $this->assertSame('results:2026-10-24', $results->content_key);
        $this->assertStringContainsString('Arsenal 2–1 Chelsea', $results->message);
    }

    public function test_admin_page_shows_errors_and_requeues_failed_posts(): void
    {
        $this->artisan('twitter:engage');
        $post = GrowthPost::where('type', 'news')->first();
        $post->update(['status' => 'failed', 'attempts' => 3, 'last_error' => 'X channel needs reconnecting']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.twitter.index'))->assertOk()
            ->assertSee('AUTOPOST ON')->assertSee('X channel needs reconnecting')->assertSee('Retry');
        $this->actingAs($admin)->post(route('admin.twitter.posts.queue', $post))->assertRedirect();

        $this->assertSame(['queued', 0, null], [$post->fresh()->status, $post->fresh()->attempts, $post->fresh()->last_error]);
    }

    public function test_fixture_card_is_a_16_by_9_png(): void
    {
        $response = $this->get(route('matches.card', $this->match))->assertOk()->assertHeader('Content-Type', 'image/png');

        [$width, $height] = getimagesize($response->baseResponse->getFile()->getPathname());
        $this->assertSame([1200, 675], [$width, $height]);
    }

    public function test_text_fits_x_weighting(): void
    {
        $link = 'https://matchday.africa/matches/1234567890?with=a-very-long-query-string';
        $text = XText::compose(str_repeat('Goal! ', 80), $link, '#MatchdayAfrica');

        $this->assertSame(280, XText::length($text));
        $this->assertStringEndsWith("…\n\n{$link}\n#MatchdayAfrica", $text);
        $this->assertSame(23 + 1 + 2, XText::length("{$link} ⚽"));
    }

    public function test_instagram_metadata_is_built_from_the_schema_or_omitted(): void
    {
        $ok = BufferClient::buildPostMetadata(['metadata'], ['instagram'], [['name' => 'type', 'required' => true], ['name' => 'shouldShareToFeed', 'required' => true]], ['POST', 'REEL'], 'instagram', 'reel');
        $this->assertSame(['instagram' => ['type' => 'REEL', 'shouldShareToFeed' => true]], $ok['metadata']);

        $this->assertNull(BufferClient::buildPostMetadata(['text'], ['instagram'], ['type'], ['post'], 'instagram', 'post')['metadata']);

        $missing = BufferClient::buildPostMetadata(['metadata'], ['instagram'], ['type', ['name' => 'firstComment', 'required' => true]], ['post'], 'instagram', 'post');
        $this->assertSame(['firstComment'], $missing['missing']);
    }
}
