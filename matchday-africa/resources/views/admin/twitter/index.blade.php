@extends('layouts.admin')
@section('title','X Post Scheduler')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">GROWTH DESK</p><h1>X post scheduler.</h1><p>Create match-led posts, pair them with site artwork and hand them to X.</p></div><form method="POST" action="{{ route('admin.twitter.generate') }}">@csrf<button class="md-primary">Generate fresh posts</button></form></div>
@endsection
@section('content')
<div class="md-xdesk">
 <section class="md-xguide"><span>FREE PUBLISHING FLOW</span><ol><li><b>01</b> Review the copy</li><li><b>02</b> Download its image</li><li><b>03</b> Open X and schedule</li></ol><p>X requires the final media upload and confirmation.</p></section>
 <details class="md-xcreate"><summary>Create a custom post <b>＋</b></summary><form method="POST" action="{{ route('admin.twitter.posts.store') }}">@csrf<label><span>POST COPY</span><textarea name="message" maxlength="280" required placeholder="Write the post…"></textarea></label><label><span>APP IMAGE URL</span><input name="image_url" type="url" required placeholder="https://matchday.africa/…"></label><label><span>DESTINATION</span><input name="destination_url" type="url" placeholder="https://matchday.africa/…"></label><label><span>SCHEDULE FOR</span><input name="scheduled_at" type="datetime-local" required value="{{ now()->addHour()->format('Y-m-d\TH:i') }}"></label><button class="md-primary">Save draft</button></form></details>
 <header class="md-xsection"><div><p class="md-eyebrow">CONTENT QUEUE</p><h2>Ready for the timeline.</h2></div><span>{{ $posts->total() }} POSTS</span></header>
 <div class="md-xgrid">
 @forelse($posts as $post)<article class="md-xcard {{ $post->status==='published'?'is-published':'' }}">
  <a class="md-xmedia" href="{{ $post->destination_url ?: '#' }}" target="_blank">@if($post->image_url)<img src="{{ $post->image_url }}" alt="Post artwork" onerror="this.src='{{ route('images.social-default') }}'">@else<img src="{{ route('images.social-default') }}" alt="Matchday Africa artwork">@endif<span>{{ strtoupper($post->type) }}</span></a>
  <form method="POST" action="{{ route('admin.twitter.posts.update',$post) }}" class="md-xform">@csrf @method('PUT')
   <header><span class="md-xstatus {{ $post->status }}">{{ strtoupper($post->status) }}</span><time>{{ optional($post->scheduled_at)->format('D, d M · H:i') ?: 'Not scheduled' }}</time></header>
   <label><span>POST COPY</span><textarea name="message" maxlength="280" required>{{ $post->message }}</textarea></label>
   <label><span>IMAGE</span><input name="image_url" type="url" required value="{{ $post->image_url ?: route('images.social-default') }}"></label>
   <label><span>LINK</span><input name="destination_url" type="url" value="{{ $post->destination_url }}"></label>
   <label><span>TIME</span><input name="scheduled_at" type="datetime-local" required value="{{ optional($post->scheduled_at)->format('Y-m-d\TH:i') ?: now()->format('Y-m-d\TH:i') }}"></label>
   <button class="md-xsave">Save changes</button>
  </form>
  <footer><a href="{{ $post->image_url ?: route('images.social-default') }}" download target="_blank">Download image</a><a class="md-xopen" href="https://twitter.com/intent/tweet?text={{ urlencode($post->message) }}" target="_blank" rel="noopener">Open in X ↗</a>@if($post->status!=='published')<form method="POST" action="{{ route('admin.twitter.posts.posted',$post) }}">@csrf<button>Mark posted</button></form>@endif<form method="POST" action="{{ route('admin.twitter.posts.destroy',$post) }}">@csrf @method('DELETE')<button class="danger">Delete</button></form></footer>
 </article>@empty<div class="md-ops-empty">No drafts yet. Generate the first set above.</div>@endforelse
 </div><div class="md-xpages">{{ $posts->links() }}</div>
</div>
@endsection
