@extends('layouts.admin')
@section('title','Slideshows')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">COLOUR SLIDESHOWS</p><h1>Check, then approve.</h1><p>Videos made from the site's own data for African players and players of confirmed African descent. Nothing reaches <a href="{{ route('admin.social-queue.index') }}">the social queue</a>, public pages or YouTube until it is approved here.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-notice md-flash-error">{{ session('error') }}</p>@endif

 <table class="md-signup-table"><tbody>
  <tr><th>Generation</th><td>{{ $enabled ? 'On (SLIDESHOW_ENABLED).' : 'Off: set SLIDESHOW_ENABLED=true to make new slideshows. Reviewing still works.' }}</td></tr>
  <tr><th>ffmpeg</th><td><code>{{ $ffmpeg }}</code>: {{ $ffmpegMissing ? 'missing '.implode(', ', $ffmpegMissing).' (renders fail until this is fixed; set SLIDESHOW_FFMPEG).' : 'has everything needed.' }} Video mode: {{ $videoMode === 'filters' ? 'ffmpeg filters' : 'frames drawn by the site (works with the reel renderer\'s small ffmpeg)' }}.</td></tr>
  <tr><th>Music</th><td>
   {{ $musicCredit }}
   <br>{{ $musicChecked ? 'File found and checked against the Free Music Archive original. ' : '' }}{{ is_file($music) ? 'File found'.(!empty($musicInfo['uploaded_at']) ? ' (uploaded '.\Carbon\Carbon::parse($musicInfo['uploaded_at'])->setTimezone('Europe/London')->format('j M Y H:i').')' : '').'.' : 'No file yet: every render fails until it is in place (the deploy fetches it, or upload it here).' }}
   @if(!empty($musicInfo['source_url']))<br>Source: <a href="{{ $musicInfo['source_url'] }}" target="_blank" rel="noopener">{{ $musicInfo['source_url'] }}</a>@else<br>Source link not recorded yet.@endif
   <form method="post" action="{{ route('admin.slideshows.music') }}" enctype="multipart/form-data" style="margin-top:.5rem">@csrf
    <label>Track (mp3, m4a or wav; this server takes up to {{ number_format($uploadLimit / 1048576, 0) }} MB) <input type="file" name="track" accept=".mp3,.m4a,.aac,.wav,.flac,.ogg,audio/*" required></label>
    <label>Where it came from (licence page) <input type="url" name="source_url" value="{{ $musicInfo['source_url'] ?? '' }}" placeholder="https://…" style="min-width:18rem"></label>
    <button type="submit">Upload music</button>
   </form>
   <small>Kept private in storage/app/music; only the finished videos are public. Every caption and YouTube description carries the credit.</small>
  </td></tr>
 </tbody></table>

 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>).</p></section>
 @elseif($shows->isEmpty())
  <p>No slideshows in the last week.</p>
 @else
 <table class="md-signup-table"><thead><tr><th>Slideshow</th><th>Video</th><th>Caption and credits</th><th>Actions</th></tr></thead><tbody>
  @foreach($shows as $show)
   <tr id="slideshow-{{ $show->id }}">
    <td><b>{{ $show->label() }}</b> <small><code>slideshow:{{ $show->id }}</code></small>
     <br>{{ $show->title() }}
     @if($show->match)<br><a href="{{ route('matches.show', $show->match) }}" target="_blank" rel="noopener">{{ $show->match->homeTeam?->name }} v {{ $show->match->awayTeam?->name }}</a> <small>{{ $show->match->league?->name }}</small>@endif
     @if($show->player)<br><small>Featured: {{ $show->player->name }} ({{ \App\Support\AfricanScope::label($show->player) ?? 'not in scope' }})</small>
      @php($p = data_get($show->player->metadata, 'commons_photo'))
      <br><small>Slide photo: @if(($p['status'] ?? null) === 'ok')<a href="{{ $p['source_url'] ?? '#' }}" target="_blank" rel="noopener">{{ $p['title'] ?? 'Commons' }}</a> ({{ $p['licence'] ?? '' }})@elseif($p){{ $p['reason'] ?? 'none found' }}@else not looked up yet @endif</small>
      <form method="post" action="{{ route('admin.slideshows.photo') }}" style="margin-top:.25rem">@csrf<input type="hidden" name="player_id" value="{{ $show->player->id }}">
       <input name="file" placeholder="File:Name.jpg or Commons link (empty: look up)" aria-label="Commons file for {{ $show->player->name }}" style="min-width:14rem"> <button type="submit">Use photo</button></form>
     @endif
     <br><small>Status: <b>{{ $show->status }}</b>{{ $show->approved ? ', approved' : '' }} · priority {{ $show->priority }}@if($show->duration_seconds) · {{ $show->duration_seconds }} s @endif</small>
     @if($show->error)<br><small class="md-flash-error">{{ $show->error }}</small>@endif
     @if($show->youtube_status)<br><small>YouTube: {{ $show->youtube_status }}@if($show->youtube_video_id) · <a href="https://www.youtube.com/shorts/{{ $show->youtube_video_id }}" target="_blank" rel="noopener">Short</a>@endif</small>@endif
    </td>
    <td>@if($show->videoUrl())<video controls playsinline preload="none" width="180" @if($show->posterUrl()) poster="{{ $show->posterUrl() }}" @endif src="{{ $show->videoUrl() }}"></video>@else<small>Not rendered yet.</small>@endif</td>
    <td>
     @if($show->caption)<textarea readonly rows="6" style="width:100%;min-width:16rem" aria-label="Caption">{{ $show->caption }}</textarea>@endif
     @if(data_get($show->captions, 'x'))<br><small>X ({{ \App\Support\XText::length(data_get($show->captions, 'x')) }}/{{ $xLimit }}):</small><textarea readonly rows="4" style="width:100%" aria-label="X caption">{{ data_get($show->captions, 'x') }}</textarea>@endif
     @if(data_get($show->photo, 'credit'))<br><small>Photo: <a href="{{ data_get($show->photo, 'source_url') }}" target="_blank" rel="noopener">{{ data_get($show->photo, 'credit') }}</a></small>@endif
     @if($show->music)<br><small>{{ \App\Services\Slideshow\Slideshows::musicCredit($show->music) }}</small>@endif
     @if($show->facts)<details><summary><small>Facts used</small></summary><pre style="white-space:pre-wrap;font-size:11px;max-height:16rem;overflow:auto">{{ json_encode(collect($show->facts)->except(['loudness'])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif
    </td>
    <td>
     @if($show->status === 'ready' && !$show->approved)
      <form method="post" action="{{ route('admin.slideshows.approve', $show) }}">@csrf<button type="submit">Approve</button></form>
     @elseif($show->approved)
      <form method="post" action="{{ route('admin.slideshows.unapprove', $show) }}">@csrf<button type="submit">Take off the queue</button></form>
     @endif
     @if(!in_array($show->status, ['pending', 'rendering'], true))
      <form method="post" action="{{ route('admin.slideshows.regenerate', $show) }}">@csrf<button type="submit">Regenerate</button></form>
     @endif
     @if($show->approved && $show->videoUrl())<a href="{{ route('slideshows.show', $show) }}" target="_blank" rel="noopener">Public page</a>@endif
    </td>
   </tr>
  @endforeach
 </tbody></table>
 @endif
</div>
@endsection
