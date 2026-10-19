<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class PublicPathTest extends TestCase
{
    public function test_split_web_root_applies_to_assets_and_storage_link(): void
    {
        $previous = public_path();
        $public = sys_get_temp_dir().'/matchday-public';
        config(['app.public_path' => $public]);
        (new AppServiceProvider($this->app))->register();

        $this->assertSame($public.'/build/manifest.json', public_path('build/manifest.json'));
        $this->assertArrayNotHasKey($previous.'/storage', config('filesystems.links'));
        $this->assertSame(storage_path('app/public'), config('filesystems.links')[$public.'/storage']);
    }
}
