<?php
namespace App\Http\Controllers;use App\Models\{Blog,CreatorProfile};use Illuminate\Http\Request;use Illuminate\Support\Str;
class CreatorController extends Controller {
 public function show(CreatorProfile $creator){abort_unless($creator->status==='approved',404);$creator->load(['blogs'=>fn($q)=>$q->published()->latest('published_at')]);return view('creators.show',compact('creator'));}
 public function studio(){abort_unless(auth()->user()->creatorProfile?->status==='approved',403);$creator=auth()->user()->creatorProfile;$posts=$creator->blogs()->latest()->get();$earnings=$creator->earnings()->latest()->get();return view('creators.studio',compact('posts','earnings','creator'));}
 public function submit(Request $r){$creator=auth()->user()->creatorProfile;abort_unless($creator?->status==='approved',403);$d=$r->validate(['title'=>'required|string|max:180','excerpt'=>'required|string|max:500','content'=>'required|string|min:100']);Blog::create($d+['slug'=>Str::slug($d['title']).'-'.Str::lower(Str::random(4)),'creator_profile_id'=>$creator->id,'author_name'=>$creator->display_name,'status'=>'draft','review_status'=>'pending']);return back()->with('success','Draft submitted for editorial review.');}
}
