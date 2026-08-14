<?php

namespace Tests\Feature;

use App\Models\{CommerceProduct, CreatorProfile, DigitalEntitlement, SponsorPlacement, User};
use App\Services\CommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThreeCommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_digital_order_is_fulfilled_once(): void
    {
        $user=User::factory()->create(); $product=CommerceProduct::where('slug','warrior-wallpaper-pack')->firstOrFail();
        $service=app(CommerceService::class); $order=$service->createOrder($user,$product); $service->fulfill($order); $service->fulfill($order->fresh());
        $this->assertEquals('paid',$order->fresh()->status);
        $this->assertEquals(1,DigitalEntitlement::where('user_id',$user->id)->count());
    }

    public function test_membership_and_creator_share_are_activated(): void
    {
        $user=User::factory()->create(); $creatorUser=User::factory()->create();
        $creator=CreatorProfile::create(['user_id'=>$creatorUser->id,'display_name'=>'The Griot','slug'=>'the-griot','bio'=>'Independent football storyteller.','status'=>'approved']);
        $product=CommerceProduct::where('slug','premium-monthly')->firstOrFail(); $service=app(CommerceService::class);
        $order=$service->createOrder($user,$product,$creator->id); $service->fulfill($order,['customer'=>'cus_test','subscription'=>'sub_test']);
        $this->assertTrue($user->fresh()->isPremium());
        $this->assertDatabaseHas('creator_earnings',['commerce_order_id'=>$order->id,'amount'=>40]);
    }

    public function test_shop_and_sponsor_redirect_are_available(): void
    {
        $this->get('/shop')->assertOk()->assertSee('THE QUARTERMASTER');
        $sponsor=SponsorPlacement::create(['name'=>'Test partner','slot'=>'home','headline'=>'Built for matchday','destination_url'=>'https://example.com','active'=>true]);
        $this->get(route('sponsors.click',$sponsor))->assertRedirect('https://example.com');
        $this->assertEquals(1,$sponsor->fresh()->clicks);
    }
}
