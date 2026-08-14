<?php
namespace App\Services;

use App\Models\{CommerceOrder, CommerceProduct, CreatorEarning, DigitalEntitlement};
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommerceService
{
    public function createOrder(User $user, CommerceProduct $product, ?int $creatorId = null): CommerceOrder
    {
        return DB::transaction(function () use ($user, $product, $creatorId) {
            $order = CommerceOrder::create(['user_id'=>$user->id,'creator_profile_id'=>$creatorId,'email'=>$user->email,'total'=>$product->price,'currency'=>$product->currency,'status'=>'pending']);
            $order->items()->create(['commerce_product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>1,'unit_amount'=>$product->price,'metadata'=>['type'=>$product->type]]);
            return $order;
        });
    }

    public function fulfill(CommerceOrder $order, array $stripe = []): CommerceOrder
    {
        if ($order->status === 'paid') return $order;
        return DB::transaction(function () use ($order, $stripe) {
            $order->update(['status'=>'paid','paid_at'=>now(),'stripe_payment_intent_id'=>$stripe['payment_intent'] ?? $order->stripe_payment_intent_id]);
            foreach ($order->items()->with('product')->get() as $item) {
                if ($item->product?->type === 'digital' && $order->user_id) DigitalEntitlement::firstOrCreate(
                    ['user_id'=>$order->user_id,'commerce_product_id'=>$item->product->id],
                    ['commerce_order_id'=>$order->id,'granted_at'=>now()]
                );
                if ($item->product?->type === 'membership' && $order->user) $order->user->update([
                    'stripe_customer_id'=>$stripe['customer'] ?? $order->user->stripe_customer_id,
                    'stripe_subscription_id'=>$stripe['subscription'] ?? $order->user->stripe_subscription_id,
                    'subscription_status'=>'active','premium_until'=>now()->addMonth(),
                ]);
            }
            if ($order->creator_profile_id) CreatorEarning::firstOrCreate(
                ['commerce_order_id'=>$order->id,'creator_profile_id'=>$order->creator_profile_id],
                ['amount'=>(int) round($order->total * (config('services.stripe.creator_share',10) / 100)),'currency'=>$order->currency,'source'=>'store_referral','status'=>'pending']
            );
            return $order->fresh();
        });
    }
}
