<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_installable_on_android_and_iphone(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $purposes = collect($manifest['icons'])->groupBy('purpose');
        $this->assertContains('512x512', $purposes['any']->pluck('sizes')->all());
        $this->assertContains('512x512', $purposes['maskable']->pluck('sizes')->all());
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertFileExists(public_path('icons/badge-96.png'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString("'/offline.html'", file_get_contents(public_path('sw.js')));
    }

    public function test_pages_link_the_manifest_and_offer_the_app(): void
    {
        $this->get('/app')->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-mobile-web-app-capable', false)
            ->assertSee('Add to Home Screen')
            ->assertSee('data-install-page', false)
            ->assertDontSee('id="md-install"', false);

        $this->get('/terms')->assertOk()->assertSee('id="md-install"', false)->assertSee(route('app.install'), false);
    }

    public function test_alerts_offer_needs_an_account_and_push(): void
    {
        config(['push.enabled' => false]);
        $this->get('/terms')->assertSee('data-vapid=""', false);
        $this->get('/app')->assertSee('Alerts need a free account');

        $this->actingAs(User::factory()->create())->get('/app')->assertOk()->assertDontSee('Alerts need a free account');
    }

    public function test_sitemap_lists_the_app_page(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee(route('app.install'), false);
    }
}
