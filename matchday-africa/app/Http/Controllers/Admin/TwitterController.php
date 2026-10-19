<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\GrowthPost;
use App\Services\GrowthPostGenerator;
use App\Support\BufferUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
class TwitterController extends Controller {
    public function index(){return view('admin.twitter.index',['posts'=>GrowthPost::orderByRaw('published_at IS NOT NULL')->orderBy('scheduled_at')->latest('id')->paginate(30),'auto'=>config('buffer.x.auto')&&config('buffer.key')&&array_filter(config('buffer.channels')),'usage'=>BufferUsage::summary()]);}
    public function generate(GrowthPostGenerator $generator): RedirectResponse{return back()->with('success',$generator->generate().' new posts generated.');}
    public function store(Request $request): RedirectResponse{$data=$request->validate(['message'=>'required|string|max:280','image_url'=>'nullable|url','destination_url'=>'nullable|url','scheduled_at'=>'required|date']);GrowthPost::create($data+['content_key'=>sha1($data['message'].microtime()),'type'=>'custom','status'=>config('buffer.x.auto')?'queued':'draft']);return back()->with('success','Draft saved.');}
    public function update(Request $request,GrowthPost $post): RedirectResponse{$data=$request->validate(['message'=>'required|string|max:280','image_url'=>'nullable|url','destination_url'=>'nullable|url','scheduled_at'=>'required|date']);$post->update($data);return back()->with('success','Draft updated.');}
    public function posted(GrowthPost $post): RedirectResponse{$post->update(['status'=>'published','published_at'=>now()]);return back()->with('success','Post marked as published.');}
    /** Hand a draft, failed or expired post to Buffer at its time (or now if that has passed). */
    public function queue(GrowthPost $post): RedirectResponse{$post->update(['status'=>'queued','attempts'=>0,'last_error'=>null,'expires_at'=>null,'scheduled_at'=>$post->scheduled_at?->isFuture()?$post->scheduled_at:now()]);return back()->with('success',config('buffer.x.auto')?'Queued for Buffer.':'Queued; it is sent once BUFFER_AUTO is on.');}
    public function destroy(GrowthPost $post): RedirectResponse{$post->delete();return back()->with('success','Draft deleted.');}
}
