<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SocialMediaAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_upload_creatives_and_see_their_public_urls(): void
    {
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 11, 7));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/social-media')->assertOk()->assertSee('Nothing uploaded yet.');

        $this->actingAs($admin)->post('/admin/social-media', ['file' => UploadedFile::fake()->create('Call Out The Griot!.mp4', 2048, 'video/mp4')])
            ->assertRedirect('/admin/social-media')->assertSessionHas('uploaded');
        $this->actingAs($admin)->post('/admin/social-media', ['file' => UploadedFile::fake()->image('Fixture card.PNG')])
            ->assertRedirect('/admin/social-media');

        $files = Storage::disk('public')->allFiles('social');
        $this->assertCount(2, $files);
        $this->assertMatchesRegularExpression('~^social/2026-11/call-out-the-griot-[a-z0-9]{8}\.mp4$~', collect($files)->first(fn ($f) => str_ends_with($f, '.mp4')));
        $this->assertMatchesRegularExpression('~^social/2026-11/fixture-card-[a-z0-9]{8}\.png$~', collect($files)->first(fn ($f) => str_ends_with($f, '.png')));

        $page = $this->actingAs($admin)->get('/admin/social-media')->assertOk();
        foreach ($files as $file) {
            $page->assertSee(Storage::disk('public')->url($file))->assertSee('Copy');
        }
    }

    public function test_other_types_and_large_files_are_refused(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post('/admin/social-media', ['file' => UploadedFile::fake()->create('script.php', 1, 'text/x-php')])
            ->assertSessionHasErrors('file');
        $this->actingAs($admin)->post('/admin/social-media', ['file' => UploadedFile::fake()->create('huge.mp4', 200 * 1024 + 1, 'video/mp4')])
            ->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('public')->allFiles('social'));
    }

    public function test_only_admins_can_use_it(): void
    {
        $this->get('/admin/social-media')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/social-media')->assertStatus(403);
    }
}
