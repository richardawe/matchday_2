<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignupNudgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_get_the_nudge_with_the_value_proposition(): void
    {
        $this->get('/community')->assertOk()
            ->assertSee('id="md-nudge"', false)
            ->assertSee('Free predictions league')
            ->assertSee('Match alerts')
            ->assertSee('Matchday Radio')
            ->assertSee("s.views>=3", false);
    }

    public function test_signed_in_fans_and_auth_pages_do_not_get_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/community')->assertOk()->assertDontSee('id="md-nudge"', false);
    }

    public function test_the_visitor_cookie_reaches_analytics_unencrypted(): void
    {
        $id = '0b6f3f0e-8a4e-4f7b-9a59-6c1f1d2b3c4d';
        $this->withCredentials()->withUnencryptedCookie('md_visitor', $id)->postJson(route('analytics.track'), ['event' => 'page_view', 'path' => '/'])->assertNoContent();

        $this->assertDatabaseHas('analytics_events', ['event' => 'page_view', 'visitor_id' => $id]);
    }
}
