<?php
namespace App\Http\Controllers\Admin;use App\Http\Controllers\Controller;use App\Models\{Blog,CreatorProfile};
class CreatorController extends Controller {
 public function index(){return view('admin.creators.index',['creators'=>CreatorProfile::with('user')->latest()->get(),'drafts'=>Blog::where('review_status','pending')->with('creatorProfile')->latest()->get()]);}
 public function approve(CreatorProfile $creator){$creator->update(['status'=>'approved']);return back()->with('success',$creator->display_name.' approved.');}
 public function reject(CreatorProfile $creator){$creator->update(['status'=>'rejected']);return back()->with('success','Application declined.');}
 public function publish(Blog $blog){$blog->update(['review_status'=>'approved','status'=>'published','published_at'=>now()]);return back()->with('success','Story published.');}
 public function returnDraft(Blog $blog){$blog->update(['review_status'=>'changes_requested']);return back()->with('success','Draft returned for changes.');}
}
