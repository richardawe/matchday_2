@extends('layouts.admin')
@section('title','X Post Scheduler')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">GROWTH DESK</p><h1>Social post scheduler.</h1><p>News, fixture previews and daily round-ups, planned every ten minutes{{ $auto ? ' and sent through Buffer when a slot is free' : '' }}.</p></div><form method="POST" action="{{ route('admin.twitter.generate') }}">@csrf<button class="md-primary">Generate fresh posts</button></form></div>
@endsection
@section('content')
<div class="md-xdesk">
 @if($auto)<section class="md-xguide"><span>AUTOPOST ON</span><ol><li><b>{{ count(config('buffer.slots')) }}</b> posts a day per account, at {{ implode(', ', config('buffer.slots')) }} WAT</li><li><b>{{ $usage['used'] }}</b> of {{ $usage['limit'] }} Buffer requests this month</li></ol><p>Edit or delete a queued post before its time. Failed posts show Buffer's reason.</p></section>
 @else<section class="md-xguide"><span>MANUAL FLOW</span><ol><li><b>01</b> Review the copy</li><li><b>02</b> Download its image</li><li><b>03</b> Open X and schedule</li></ol><p>Set BUFFER_API_KEY, the BUFFER_CHANNEL_* IDs and BUFFER_AUTO=true to post automatically.</p></section>@endif
 <details class="md-xcreate"><summary>Create a custom post <b>＋</b></summary><form method="POST" action="{{ route('admin.twitter.posts.store') }}">@csrf<label><span>POST COPY</span><textarea name="message" maxlength="280" required placeholder="Write the post…"></textarea></label><label><span>APP IMAGE URL</span><input name="image_url" type="url" placeholder="Optional: https://matchday.africa/…"></label><label><span>DESTINATION</span><input name="destination_url" type="url" placeholder="https://matchday.africa/…"></label><label><span>SCHEDULE FOR</span><input name="scheduled_at" type="datetime-local" required value="{{ now()->addHour()->format('Y-m-d\TH:i') }}"></label><button class="md-primary">{{ $auto ? 'Queue post' : 'Save draft' }}</button></form></details>
 <header class="md-xsection"><div><p class="md-eyebrow">CONTENT QUEUE</p><h2>Ready for the timeline.</h2></div><span>{{ $posts->total() }} POSTS</span></header>
 <div class="md-xgrid">
 @forelse($posts as $post)<article class="md-xcard {{ in_array($post->status,['published','sent'],true)?'is-published':'' }}">
  <a class="md-xmedia" href="{{ $post->destination_url ?: '#' }}" target="_blank">@if($post->image_url)<img src="{{ $post->image_url }}" alt="Post artwork" onerror="this.src='{{ route('images.social-default') }}'">@else<img src="{{ route('images.social-default') }}" alt="Matchday Africa artwork">@endif<span>{{ strtoupper($post->type) }} · {{ strtoupper($post->channel ?? 'x') }}</span></a>
  <form method="POST" action="{{ route('admin.twitter.posts.update',$post) }}" class="md-xform">@csrf @method('PUT')
   <header><span class="md-xstatus {{ $post->status }}">{{ strtoupper($post->status) }}</span><time>{{ optional($post->scheduled_at)->format('D, d M · H:i') ?: 'Not scheduled' }}</time></header>
   <label><span>POST COPY</span><textarea name="message" maxlength="280" required>{{ $post->message }}</textarea></label>
   @if($post->last_error)<p class="md-xerror">{{ $post->last_error }}</p>@endif
   <label><span>IMAGE</span><input name="image_url" type="url" value="{{ $post->image_url }}" placeholder="None: X shows the link card"></label>
   <label><span>LINK</span><input name="destination_url" type="url" value="{{ $post->destination_url }}"></label>
   <label><span>TIME</span><input name="scheduled_at" type="datetime-local" required value="{{ optional($post->scheduled_at)->format('Y-m-d\TH:i') ?: now()->format('Y-m-d\TH:i') }}"></label>
   <button class="md-xsave">Save changes</button>
  </form>
  <footer><a href="{{ $post->image_url ?: route('images.social-default') }}" download target="_blank">Download image</a><a class="md-xopen" href="https://twitter.com/intent/tweet?text={{ urlencode($post->message) }}" target="_blank" rel="noopener">Open in X ↗</a>@if(in_array($post->status,['draft','failed','expired'],true))<form method="POST" action="{{ route('admin.twitter.posts.queue',$post) }}">@csrf<button>{{ $post->status==='draft'?'Queue for Buffer':'Retry' }}</button></form>@endif @if(!in_array($post->status,['published','sent'],true))<form method="POST" action="{{ route('admin.twitter.posts.posted',$post) }}">@csrf<button>Mark posted</button></form>@endif<form method="POST" action="{{ route('admin.twitter.posts.destroy',$post) }}">@csrf @method('DELETE')<button class="danger">Delete</button></form></footer>
 </article>@empty<div class="md-ops-empty">No drafts yet. Generate the first set above.</div>@endforelse
 </div><div class="md-xpages">{{ $posts->links() }}</div>
</div>
@endsection
