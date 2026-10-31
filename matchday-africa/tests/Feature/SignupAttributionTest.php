<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Attribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignupAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_with_utm_tags_sets_the_attribution_cookie(): void
    {
        $response = $this->get('/terms?utm_source=x&utm_medium=social&utm_campaign=reel');

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Attribution::COOKIE);
        $this->assertNotNull($cookie);
    }

    public function test_signup_stores_the_first_touch_source(): void
    {
        $touch = json_encode(['utm_source' => 'whatsapp', 'utm_medium' => 'share', 'utm_campaign' => 'match', 'signup_landing' => '/matches/9']);

        $this->withCookie(Attribution::COOKIE, $touch)->post('/register', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'utm_source' => 'whatsapp', 'utm_medium' => 'share', 'utm_campaign' => 'match', 'signup_landing' => '/matches/9']);
    }

    public function test_a_later_utm_visit_does_not_replace_an_earlier_utm_touch(): void
    {
        $touch = json_encode(['utm_source' => 'x']);
        $response = $this->withCookie(Attribution::COOKIE, $touch)->get('/terms?utm_source=tiktok');

        $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Attribution::COOKIE));
    }

    public function test_admin_sees_signups_sources_and_premium(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['utm_source' => 'x', 'utm_campaign' => 'reel']);
        User::factory()->create(['subscription_status' => 'active', 'premium_until' => now()->addWeek(), 'signup_referrer' => 'https://www.google.com/search']);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()
            ->assertSee('New signups per day.')
            ->assertSee('google.com')
            ->assertSee('reel')
            ->assertSee('PREMIUM NOW');
    }

    public function test_signups_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.users.index'))->assertStatus(403);
    }
}
