<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\{CommerceOrder,CommerceProduct,CreatorEarning,SponsorPlacement};
use Illuminate\Http\Request;
class CommerceController extends Controller {
 public function index(){return view('admin.commerce.index',['orders'=>CommerceOrder::with('items')->latest()->limit(30)->get(),'products'=>CommerceProduct::orderBy('type')->get(),'sponsors'=>SponsorPlacement::latest()->get(),'earnings'=>CreatorEarning::with('creator')->latest()->get(),'revenue'=>CommerceOrder::where('status','paid')->sum('total')]);}
 public function sponsor(Request $r){$d=$r->validate(['name'=>'required|max:120','slot'=>'required|in:home,predictions,matches,war','headline'=>'required|max:180','destination_url'=>'required|url','image_url'=>'nullable|url','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at']);SponsorPlacement::create($d+['active'=>true]);return back()->with('success','Sponsor placement is live.');}
 public function toggleProduct(CommerceProduct $product){$product->update(['active'=>!$product->active]);return back()->with('success','Product availability updated.');}
 public function pay(CreatorEarning $earning){$earning->update(['status'=>'paid','paid_at'=>now()]);return back()->with('success','Creator earning marked paid.');}
}
