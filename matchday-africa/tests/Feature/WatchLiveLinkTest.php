<?php

namespace Tests\Feature;

use App\Models\YoutubeBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchLiveLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_watch_live_is_hidden_until_the_stream_is_on(): void
    {
        config(['stream.enabled' => false]);
        $this->get('/')->assertOk()->assertDontSee('Watch live');
    }

    public function test_watch_live_goes_to_the_stream_screen_then_to_youtube_while_on_air(): void
    {
        config(['stream.enabled' => true]);
        $this->get('/')->assertOk()->assertSee('Watch live')->assertSee(route('stream.show'), false);

        // A private test broadcast is never linked; the live matchday broadcast is.
        YoutubeBroadcast::create(['broadcast_id' => 'testvideo01', 'window_key' => 'test-1', 'title' => 'Test', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'privacy' => 'private', 'status' => 'live']);
        $this->app->forgetScopedInstances(); // a new request (the lookup is cached per request)
        $this->get('/')->assertDontSee('youtube.com/watch?v=testvideo01', false);
        YoutubeBroadcast::create(['broadcast_id' => 'matchday001', 'window_key' => now()->toDateString(), 'title' => 'Matchday Live', 'starts_at' => now(), 'ends_at' => now()->addHours(8), 'privacy' => 'unlisted', 'status' => 'live']);
        $this->app->forgetScopedInstances();
        $this->get('/')->assertSee('https://www.youtube.com/watch?v=matchday001', false)->assertSee('Watch every goal as it goes in');
    }
}
