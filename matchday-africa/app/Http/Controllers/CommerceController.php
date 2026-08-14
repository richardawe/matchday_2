<?php
namespace App\Http\Controllers;

use App\Models\{CommerceOrder, CommerceProduct, CreatorProfile, DigitalEntitlement};
use App\Services\{CommerceService, StripeCheckoutService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class CommerceController extends Controller
{
    public function shop(Request $request){$products=CommerceProduct::active()->where('type','!=','membership')->get();return view('commerce.shop',compact('products'));}
    public function premium(){return view('commerce.premium',['product'=>CommerceProduct::where('slug','premium-monthly')->firstOrFail()]);}
    public function checkout(Request $request, CommerceProduct $product, CommerceService $commerce, StripeCheckoutService $stripe){
        abort_unless($product->active,404); $creatorId=null;
        if ($request->filled('creator')) $creatorId=CreatorProfile::where('slug',$request->string('creator'))->where('status','approved')->value('id');
        $order=$commerce->createOrder($request->user(),$product,$creatorId);
        return redirect()->away($stripe->checkout($order,$product));
    }
    public function success(Request $request){$order=CommerceOrder::where('stripe_checkout_session_id',$request->string('session_id'))->where('user_id',$request->user()->id)->with('items.product')->first();return view('commerce.success',compact('order'));}
    public function library(Request $request){$entitlements=DigitalEntitlement::where('user_id',$request->user()->id)->with('product')->latest()->get();return view('commerce.library',compact('entitlements'));}
    public function download(Request $request, CommerceProduct $product){abort_unless(DigitalEntitlement::where('user_id',$request->user()->id)->where('commerce_product_id',$product->id)->exists(),403);$path=public_path($product->download_path);abort_unless(File::isDirectory($path),404);$zip=storage_path('app/'.$product->slug.'.zip');if(!File::exists($zip)){ $archive=new \ZipArchive; $archive->open($zip,\ZipArchive::CREATE|\ZipArchive::OVERWRITE); foreach(File::files($path) as $file)$archive->addFile($file->getPathname(),$file->getFilename());$archive->close(); } return response()->download($zip,$product->slug.'.zip');}
}
