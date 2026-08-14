<?php
namespace App\Http\Controllers;

use App\Models\CommerceOrder;
use App\Services\CommerceService;
use Illuminate\Http\Request;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, CommerceService $commerce){
        try {$event=Webhook::constructEvent($request->getContent(),$request->header('Stripe-Signature',''),config('services.stripe.webhook_secret'));}
        catch(\Throwable $e){return response()->json(['error'=>'Invalid webhook'],400);}
        if(in_array($event->type,['checkout.session.completed','checkout.session.async_payment_succeeded'])){
            $session=$event->data->object;$order=CommerceOrder::find($session->metadata->order_id ?? null);
            if($order && ($session->payment_status ?? 'paid')==='paid') $commerce->fulfill($order,['payment_intent'=>$session->payment_intent ?? null,'customer'=>$session->customer ?? null,'subscription'=>$session->subscription ?? null]);
        }
        if($event->type==='customer.subscription.deleted') \App\Models\User::where('stripe_subscription_id',$event->data->object->id)->update(['subscription_status'=>'cancelled','premium_until'=>now()]);
        return response()->json(['received'=>true]);
    }
}
