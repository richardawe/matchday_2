<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** public/war is a real folder, so Apache 301s /war to /war/: internal links go straight to /war/. */
class WarLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_links_point_at_war_with_the_trailing_slash(): void
    {
        $war = url('/war').'/';
        $this->get('/')->assertOk()->assertSee('href="'.$war.'"', false)->assertDontSee('href="'.url('/war').'"', false);
        $this->get('/?home=v2')->assertOk()->assertDontSee('href="'.url('/war').'"', false);
        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>'.$war.'</loc>', false);
    }

    public function test_the_war_page_answers_on_war_slash_with_that_canonical(): void
    {
        $this->get('/war/')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/war').'/">', false)
            ->assertSee('property="og:url" content="'.url('/war').'/"', false);
    }
}
