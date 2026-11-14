@extends('layouts.admin')
@section('title','Social queue')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">SOCIAL QUEUE</p><h1>Ready to post.</h1><p>Premier League reels and the generator's cards from the last {{ (int) config('social.queue_days', 14) }} days, newest first. The posting bot reads the same list from <code>/api/social-queue</code> and marks what it posts; you can mark posts by hand here. <a href="{{ route('admin.social-media.index') }}">Social media</a> · <a href="{{ route('admin.slideshows.index') }}">Slideshows to review</a> · <a href="{{ route('admin.videos.index') }}">Long videos</a></p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-notice md-flash-error">{{ session('error') }}</p>@endif
 @if($errors->any())<p class="md-notice md-flash-error">{{ $errors->first() }}</p>@endif

 <table class="md-signup-table"><tbody>
  <tr><th>Site autopost</th><td>{{ $autopost ? 'On: the site still posts to Buffer itself (SOCIAL_AUTOPOST_ENABLED=true).' : 'Off: the site sends nothing to Buffer, X, Instagram or TikTok.' }}</td></tr>
  <tr><th>Bot API</th><td>{{ $tokenSet ? 'Token set: the posting bot can read the queue.' : 'No SOCIAL_QUEUE_TOKEN set: the API refuses every request.' }}</td></tr>
 </tbody></table>

 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>).</p></section>
 @elseif($items->isEmpty())
  <p>Nothing in the queue yet.</p>
 @else
 <table class="md-signup-table"><thead><tr><th>Item</th><th>Media</th><th>Caption</th><th>Channels</th></tr></thead><tbody>
  @foreach($items as $item)
   <tr id="{{ str_replace(':', '-', $item['id']) }}">
    <td>
     <b>{{ $item['type'] === 'radio' ? 'Matchday Radio' : ($item['type'] === 'video' ? 'Long video' : ucfirst($item['type'])) }}</b> <small><code>{{ $item['id'] }}</code></small>
     @if(!empty($item['edition']))<br>{{ $item['edition'] }}: {{ $item['title'] }}@elseif($item['type'] === 'video')<br><a href="{{ $item['page_url'] }}" target="_blank" rel="noopener">{{ $item['title'] }}</a>@endif
     @if($item['match'])
      <br><a href="{{ $item['match']['url'] }}" target="_blank" rel="noopener">{{ $item['match']['home'] }}@if($item['match']['score'] !== null) {{ $item['match']['score'] }} @else v @endif{{ $item['match']['away'] }}</a>
      <br><small>{{ $item['match']['competition'] }} · {{ $item['match']['kickoff_uk'] }}</small>
     @endif
     <br><small>Created {{ \Carbon\Carbon::parse($item['created_at'])->setTimezone('Europe/London')->format('D j M H:i T') }}</small>
    </td>
    <td>
     @if($item['reel_url'])
      {{ $item['type'] === 'reel' ? 'Reel' : 'Video' }} ({{ $item['reel_duration_seconds'] !== null ? rtrim(rtrim(number_format($item['reel_duration_seconds'], 1), '0'), '.').' s' : 'length unknown' }}):
      <br><input readonly value="{{ $item['reel_url'] }}" aria-label="Reel URL" onclick="this.select()" style="width:100%;min-width:14rem;font-family:monospace">
      <button type="button" data-copy="{{ $item['reel_url'] }}">Copy</button> <a href="{{ $item['reel_url'] }}" target="_blank" rel="noopener">Open</a>
     @endif
     @if(in_array($item['type'], ['radio', 'slideshow', 'video'], true) && $item['reel_url'])<br><video controls preload="none" playsinline @if($item['image_url']) poster="{{ $item['image_url'] }}" @endif src="{{ $item['reel_url'] }}" style="width:9rem;aspect-ratio:9/16;background:#000;margin-top:.4rem"></video>@endif
     @if(!empty($item['audio_url']))<br><a href="{{ $item['audio_url'] }}" target="_blank" rel="noopener">Radio mp3</a>@endif
     @if($item['youtube_url'])<br><a href="{{ $item['youtube_url'] }}" target="_blank" rel="noopener">YouTube Short</a>@endif
     @if($item['image_url'])<br><a href="{{ $item['image_url'] }}" target="_blank" rel="noopener">Card image</a>@endif
    </td>
    <td><textarea readonly rows="5" style="width:100%;min-width:16rem" aria-label="Caption">{{ $item['caption'] }}</textarea>
     <button type="button" data-copy="{{ $item['caption'] }}">Copy caption</button></td>
    <td>
     @foreach($channels as $channel)
      @php($state = $item['channels'][$channel])
      <div style="margin-bottom:.4rem">
       <b>{{ $channel === 'x' ? 'X' : ucfirst($channel) }}</b>:
       @if($state['posted'])
        Posted @if($state['posted_at']){{ \Carbon\Carbon::parse($state['posted_at'])->setTimezone('Europe/London')->format('j M H:i') }}@endif
        @if($state['url'])<a href="{{ $state['url'] }}" target="_blank" rel="noopener">view</a>@endif
        @if($state['source'] === 'queue')
         <form method="POST" action="{{ route('admin.social-queue.undo') }}" style="display:inline">@csrf
          <input type="hidden" name="id" value="{{ $item['id'] }}"><input type="hidden" name="channel" value="{{ $channel }}">
          <button type="submit">Undo</button></form>
        @else
         <small>({{ $state['source'] === 'youtube_upload' ? 'Shorts upload' : 'sent by the site' }})</small>
        @endif
       @else
        <form method="POST" action="{{ route('admin.social-queue.posted') }}" style="display:inline">@csrf
         <input type="hidden" name="id" value="{{ $item['id'] }}"><input type="hidden" name="channel" value="{{ $channel }}">
         <button type="submit">Mark posted</button></form>
       @endif
      </div>
     @endforeach
    </td>
   </tr>
  @endforeach
 </tbody></table>
 @endif
</div>
<script>
document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-copy]');
  if (!button) return;
  try { await navigator.clipboard.writeText(button.dataset.copy); } catch (e) { return; }
  const label = button.textContent;
  button.textContent = 'Copied';
  setTimeout(() => { button.textContent = label; }, 1500);
});
</script>
@endsection
