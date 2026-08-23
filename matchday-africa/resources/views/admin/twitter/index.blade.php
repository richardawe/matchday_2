@extends('layouts.app')
@section('title','X Post Scheduler')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-8">
 <div class="flex justify-between items-start mb-6"><div><h1 class="text-3xl font-bold">X Post Scheduler</h1><p class="text-gray-600 mt-1">Generate, edit and publish media-rich posts for @matchdayafrica.</p></div><form method="POST" action="{{ route('admin.twitter.generate') }}">@csrf<button class="bg-black text-white px-5 py-3 rounded-lg">Generate fresh posts</button></form></div>
 @if(session('success'))<div class="bg-green-50 text-green-800 p-3 rounded mb-5">{{ session('success') }}</div>@endif
 <div class="bg-amber-50 border border-amber-200 p-4 rounded-lg mb-6"><strong>Free workflow:</strong> Download the image, click Open in X, attach the image, then publish or use X’s calendar to schedule it.</div>
 <details class="bg-white border rounded-lg p-5 mb-6"><summary class="font-semibold cursor-pointer">Create custom post</summary><form method="POST" action="{{ route('admin.twitter.posts.store') }}" class="grid gap-3 mt-4">@csrf<textarea name="message" maxlength="280" required class="border rounded p-3" placeholder="Post text"></textarea><input name="image_url" type="url" required class="border rounded p-3" placeholder="Required app image URL"><input name="destination_url" type="url" class="border rounded p-3" placeholder="Page URL"><input name="scheduled_at" type="datetime-local" required value="{{ now()->addHour()->format('Y-m-d\TH:i') }}" class="border rounded p-3"><button class="bg-blue-700 text-white rounded p-3">Save draft</button></form></details>
 <div class="grid md:grid-cols-2 gap-5">
 @forelse($posts as $post)<article class="bg-white border rounded-xl overflow-hidden {{ $post->status==='published'?'opacity-60':'' }}">
  <img src="{{ $post->image_url }}" alt="Post media" class="w-full h-56 object-cover" onerror="this.src='{{ asset('images/social-default.png') }}'">
  <form method="POST" action="{{ route('admin.twitter.posts.update',$post) }}" class="p-5 grid gap-3">@csrf @method('PUT')
   <div class="flex justify-between text-xs uppercase text-gray-500"><span>{{ $post->type }}</span><span>{{ $post->status }}</span></div>
   <textarea name="message" maxlength="280" required rows="7" class="border rounded p-3">{{ $post->message }}</textarea>
   <input name="image_url" type="url" required value="{{ $post->image_url }}" class="border rounded p-2">
   <input name="destination_url" type="url" value="{{ $post->destination_url }}" class="border rounded p-2">
   <input name="scheduled_at" type="datetime-local" required value="{{ optional($post->scheduled_at)->format('Y-m-d\TH:i') }}" class="border rounded p-2">
   <button class="border rounded p-2">Save changes</button>
  </form>
  <div class="px-5 pb-5 grid grid-cols-2 gap-2">
   <a href="{{ $post->image_url }}" download target="_blank" class="text-center bg-gray-100 rounded p-2">Download image</a>
   <a href="https://twitter.com/intent/tweet?text={{ urlencode($post->message) }}" target="_blank" rel="noopener" class="text-center bg-black text-white rounded p-2">Open in X</a>
   @if($post->status!=='published')<form method="POST" action="{{ route('admin.twitter.posts.posted',$post) }}">@csrf<button class="w-full bg-green-700 text-white rounded p-2">Mark posted</button></form>@endif
   <form method="POST" action="{{ route('admin.twitter.posts.destroy',$post) }}">@csrf @method('DELETE')<button class="w-full text-red-700 border border-red-200 rounded p-2">Delete</button></form>
  </div>
 </article>@empty<p>No drafts yet. Generate fresh posts.</p>@endforelse
 </div><div class="mt-6">{{ $posts->links() }}</div>
</div>
@endsection
