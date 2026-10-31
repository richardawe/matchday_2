<?php

namespace Tests\Feature;

use App\Models\EmailSubscriber;
use App\Models\User;
use App\Support\Attribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_offers_the_optional_weekend_email(): void
    {
        $this->get('/terms')->assertOk()->assertSee('Get weekend fixtures + results')->assertSee('action="'.route('subscribe').'"', false);
    }

    public function test_signup_is_stored_with_its_page_and_campaign(): void
    {
        $this->withCookie(Attribution::COOKIE, json_encode(['utm_source' => 'x', 'utm_campaign' => 'reel']))
            ->from('/matches/12')
            ->post(route('subscribe'), ['email' => 'Fan@Example.com ', 'source' => '/matches/12?tab=stats'])
            ->assertRedirect()->assertSessionHas('subscribed');

        $this->assertDatabaseHas('email_subscribers', ['email' => 'fan@example.com', 'list' => 'weekend', 'source' => '/matches/12', 'utm_source' => 'x', 'utm_campaign' => 'reel']);
    }

    public function test_bots_filling_the_honeypot_and_bad_emails_are_rejected(): void
    {
        $this->post(route('subscribe'), ['email' => 'bot@example.com', 'website' => 'spam'])->assertSessionHasErrors('website');
        $this->post(route('subscribe'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('email_subscribers', 0);
    }

    public function test_unsubscribe_link_works_and_signing_up_again_resubscribes(): void
    {
        $this->post(route('subscribe'), ['email' => 'fan@example.com']);
        $subscriber = EmailSubscriber::firstOrFail();

        $this->get(route('unsubscribe', $subscriber->unsubscribe_token))->assertOk()->assertSee('unsubscribed.');
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);

        $this->post(route('subscribe'), ['email' => 'fan@example.com']);
        $this->assertNull($subscriber->fresh()->unsubscribed_at);
        $this->assertDatabaseCount('email_subscribers', 1);
    }

    public function test_signups_page_counts_email_subscribers(): void
    {
        $this->post(route('subscribe'), ['email' => 'fan@example.com', 'source' => '/reels']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.users.index'))->assertOk()
            ->assertSee('EMAIL SUBSCRIBERS')->assertSee('direct · /reels');
    }
}
