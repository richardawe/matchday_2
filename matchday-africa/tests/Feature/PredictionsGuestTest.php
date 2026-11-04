<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PredictionsGuestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_asked_to_log_in_for_their_record(): void
    {
        $this->get(route('predictions.history'))->assertRedirect(route('login'));
    }

    public function test_a_guest_on_beat_the_griot_sees_the_logged_out_menu(): void
    {
        config(['griot_challenge.enabled' => true]);
        $this->get(route('griot.challenge'))->assertOk()
            ->assertDontSee(route('logout'), false)
            ->assertSee(route('login'), false)
            ->assertSee(route('register'), false)
            ->assertSeeText('Beat the Griot');

        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada-menu@example.com']);
        $this->actingAs($user)->get(route('griot.challenge'))->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('Log Out');
    }
}
