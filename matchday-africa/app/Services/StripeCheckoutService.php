<?php
namespace App\Services;

use App\Models\{CommerceOrder, CommerceProduct};
use Stripe\StripeClient;

class StripeCheckoutService
{
    public function checkout(CommerceOrder $order, CommerceProduct $product): string
    {
        abort_unless(config('services.stripe.secret'), 503, 'Stripe is not configured yet. Add the deployment keys to the environment.');
        $stripe = new StripeClient(config('services.stripe.secret'));
        $params = [
            'mode'=>$product->type === 'membership' ? 'subscription' : 'payment',
            'customer_email'=>$order->email,
            'success_url'=>route('commerce.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'=>route('shop.index'),
            'metadata'=>['order_id'=>(string)$order->id,'user_id'=>(string)$order->user_id],
            'allow_promotion_codes'=>true,
        ];
        if ($product->type === 'membership' && config('services.stripe.premium_price')) {
            $params['line_items'] = [['price'=>config('services.stripe.premium_price'),'quantity'=>1]];
        } else {
            $params['line_items'] = [['price_data'=>['currency'=>$product->currency,'unit_amount'=>$product->price,'product_data'=>['name'=>$product->name,'description'=>$product->description]],'quantity'=>1]];
        }
        if ($product->type === 'physical') $params['shipping_address_collection']=['allowed_countries'=>['GB','NG','GH','KE','ZA','US','CA']];
        $session = $stripe->checkout->sessions->create($params);
        $order->update(['stripe_checkout_session_id'=>$session->id]);
        return $session->url;
    }
}
