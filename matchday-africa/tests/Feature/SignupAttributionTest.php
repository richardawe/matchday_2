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
        $response = $this->get('/terms?utm_source=x&utm_medium=social&utm_campaign=reel', ['Referer' => 'https://news.example/story']);

        $touch = $this->touch($response);
        $this->assertSame('x', $touch['utm_source']);
        $this->assertSame('social', $touch['utm_medium']);
        $this->assertSame('reel', $touch['utm_campaign']);
        $this->assertSame('https://news.example/story', $touch['signup_referrer']);
        $this->assertSame('/terms', $touch['signup_landing']);
        $this->assertSame($touch, session(Attribution::SESSION));
    }

    public function test_the_cookie_from_the_first_visit_is_what_signup_stores(): void
    {
        $response = $this->get('/terms?utm_source=whatsapp&utm_medium=share&utm_campaign=moment');
        $touch = $this->touch($response);

        $this->withCookie(Attribution::COOKIE, json_encode($touch))->post('/register', [
            'name' => 'Ada', 'email' => 'ada-round@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'ada-round@example.com', 'utm_source' => 'whatsapp', 'utm_medium' => 'share',
            'utm_campaign' => 'moment', 'signup_landing' => '/terms',
        ]);
    }

    public function test_a_later_login_fills_attribution_the_signup_missed(): void
    {
        $user = User::factory()->create(['email' => 'late@example.com']);
        $touch = json_encode(['utm_source' => 'x', 'utm_medium' => 'social', 'utm_campaign' => 'reel', 'signup_landing' => '/reels/4', 'signup_referrer' => 'https://t.co/abc']);

        $this->withCookie(Attribution::COOKIE, $touch)->post('/login', ['email' => 'late@example.com', 'password' => 'password']);

        $this->assertDatabaseHas('users', ['email' => 'late@example.com', 'utm_source' => 'x', 'utm_campaign' => 'reel', 'signup_landing' => '/reels/4']);
    }

    public function test_www_is_sent_to_the_canonical_host_with_the_campaign_tags(): void
    {
        config(['app.url' => 'https://matchday.africa']);

        $this->get('http://www.matchday.africa/terms?utm_source=x&utm_medium=email&utm_campaign=oct')
            ->assertRedirect('https://matchday.africa/terms?utm_source=x&utm_medium=email&utm_campaign=oct');
    }

    private function touch($response): array
    {
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Attribution::COOKIE);
        $this->assertNotNull($cookie);
        $raw = $cookie->getValue();
        try {
            $raw = \Illuminate\Cookie\CookieValuePrefix::remove(app('encrypter')->decrypt($raw, false));
        } catch (\Throwable) {
        }
        $touch = json_decode($raw, true);
        $this->assertIsArray($touch);

        return $touch;
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
