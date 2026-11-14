@extends('layouts.admin')
@section('title','Long videos')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">LONG VIDEOS</p><h1>As seen on TikTok.</h1><p>Upload a long video (MP4, up to 200 MB, over 60 s unless you tick short clip). It gets its own page at <code>/reels/videos/&lt;id&gt;</code>; feature it to show it on <a href="{{ route('reels.index') }}" target="_blank" rel="noopener">/reels</a>, and put it on the <a href="{{ route('admin.social-queue.index') }}">social queue</a> for the posting bot. The bot can also upload with <code>POST /api/social-queue/videos</code>.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if($errors->any())<p class="md-notice md-flash-error">{{ $errors->first() }}</p>@endif

 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>).</p></section>
 @else
 <form class="md-admin-form" method="POST" action="{{ route('admin.videos.store') }}" enctype="multipart/form-data">
  @csrf
  <label>Video (MP4) <input type="file" name="file" accept=".mp4,video/mp4" required></label>
  <label>Poster image (optional, JPG or PNG; otherwise a frame is taken when ffmpeg runs) <input type="file" name="poster" accept=".jpg,.jpeg,.png,image/jpeg,image/png"></label>
  <label>Title <input name="title" maxlength="150" required value="{{ old('title') }}"></label>
  <label>Type <select name="type">@foreach(\App\Models\SocialVideo::TYPES as $type)<option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
  <label>Caption (used on every platform unless you set one below) <textarea name="caption" rows="3" maxlength="2200">{{ old('caption') }}</textarea></label>
  <details><summary>Captions per platform (optional)</summary>
   @foreach(\App\Services\SocialQueue::CHANNELS as $channel)
    <label>{{ $channel === 'x' ? 'X (the link is added and the whole post kept to 280)' : ucfirst($channel) }} <textarea name="captions[{{ $channel }}]" rows="2" maxlength="2200">{{ old("captions.$channel") }}</textarea></label>
   @endforeach
  </details>
  <label>Description for the page (optional) <textarea name="description" rows="3" maxlength="5000">{{ old('description') }}</textarea></label>
  <label>Credits (music, footage, voices) <input name="credits" maxlength="1000" value="{{ old('credits') }}"></label>
  <label>Campaign (utm_campaign, default documentary) <input name="campaign" maxlength="60" pattern="[A-Za-z0-9_-]+" value="{{ old('campaign') }}" placeholder="documentary"></label>
  <label>Landing page (optional path on this site; default the video's page) <input name="landing_path" maxlength="255" value="{{ old('landing_path') }}" placeholder="/beat-the-griot"></label>
  <label>TikTok link (optional) <input name="tiktok_url" type="url" maxlength="500" value="{{ old('tiktok_url') }}"></label>
  <label>YouTube link (optional) <input name="youtube_url" type="url" maxlength="500" value="{{ old('youtube_url') }}"></label>
  <label>Sort order in the row (lower first) <input name="sort_order" type="number" value="{{ old('sort_order', 0) }}"></label>
  <label><input type="checkbox" name="featured" value="1" @checked(old('featured'))> Feature on /reels</label>
  <label><input type="checkbox" name="queue" value="1" @checked(old('queue'))> Put on the social queue now</label>
  <label><input type="checkbox" name="short" value="1" @checked(old('short'))> Short clip (60 s or less)</label>
  <p><small>Saved as <code>public/generated/videos/&lt;id&gt;/video.mp4</code>. Server limits: upload_max_filesize {{ $uploadLimit }}, post_max_size {{ $postLimit }}.</small></p>
  <button class="md-primary" type="submit">Upload</button>
 </form>

 <header class="md-xsection"><div><p class="md-eyebrow">ON /REELS NOW</p><h2>The row</h2></div></header>
 @if($row->isEmpty())<p>Nothing qualifies, so /reels shows no row.</p>@else
  <ol>@foreach($row as $card)<li><a href="{{ $card['page'] }}" target="_blank" rel="noopener">{{ $card['title'] }}</a> <small>({{ $card['label'] }}, <code>{{ $card['key'] }}</code>)</small></li>@endforeach</ol>
 @endif

 <header class="md-xsection"><div><p class="md-eyebrow">UPLOADED</p><h2>Long videos</h2></div></header>
 @if($videos->isEmpty())<p>No long videos yet.</p>@else
 <table class="md-signup-table"><thead><tr><th>Video</th><th>State</th><th>Actions</th><th>Edit</th></tr></thead><tbody>
  @foreach($videos as $video)
   <tr id="video-{{ $video->id }}">
    <td>
     <b>{{ $video->title }}</b> <small><code>video:{{ $video->id }}</code></small><br>
     <small>{{ $video->typeLabel() }} · {{ $video->lengthLabel() ?? 'length unknown' }} · {{ $video->source === 'api' ? 'from the bot' : 'by '.($video->uploader?->name ?? 'an admin') }} · {{ $video->created_at?->setTimezone('Africa/Lagos')->format('j M H:i') }}</small>
     @if($video->hasVideo())<br><video controls preload="none" playsinline @if($video->posterUrl()) poster="{{ $video->posterUrl() }}" @endif src="{{ $video->videoUrl() }}" style="width:8rem;aspect-ratio:9/16;background:#000;margin-top:.4rem"></video>
      <br><a href="{{ $video->pageUrl() }}" target="_blank" rel="noopener">Page</a> · <a href="{{ $video->videoUrl() }}" target="_blank" rel="noopener">mp4</a>
     @else<br><b>The file is missing.</b>@endif
    </td>
    <td>{{ ['approved' => 'Page live, not on the queue', 'queued' => 'On the social queue', 'off' => 'Taken down'][$video->status] ?? $video->status }}<br>{{ $video->featured ? 'Featured' : 'Not featured' }}</td>
    <td>
     @foreach(['queued' => 'Put on the social queue', 'approved' => 'Take off the queue', 'off' => 'Take down'] as $status => $label)
      @continue($video->status === $status)
      <form method="POST" action="{{ route('admin.videos.status', $video) }}" style="margin-bottom:.3rem">@csrf<input type="hidden" name="status" value="{{ $status }}"><button type="submit">{{ $video->status === 'off' && $status === 'approved' ? 'Put back up' : $label }}</button></form>
     @endforeach
     <form method="POST" action="{{ route('admin.videos.feature', $video) }}">@csrf<input type="hidden" name="featured" value="{{ $video->featured ? 0 : 1 }}"><button type="submit">{{ $video->featured ? 'Unfeature' : 'Feature on /reels' }}</button></form>
    </td>
    <td>
     <details><summary>Edit</summary>
      <form class="md-admin-form" method="POST" action="{{ route('admin.videos.update', $video) }}">@csrf
       <label>Title <input name="title" maxlength="150" required value="{{ $video->title }}"></label>
       <label>Type <select name="type">@foreach(\App\Models\SocialVideo::TYPES as $type)<option value="{{ $type }}" @selected($video->type === $type)>{{ ucfirst($type) }}</option>@endforeach</select></label>
       <label>Caption <textarea name="caption" rows="3" maxlength="2200">{{ $video->caption }}</textarea></label>
       @foreach(\App\Services\SocialQueue::CHANNELS as $channel)
        <label>{{ $channel === 'x' ? 'X' : ucfirst($channel) }} caption <textarea name="captions[{{ $channel }}]" rows="2" maxlength="2200">{{ data_get($video->captions, $channel) }}</textarea></label>
       @endforeach
       <label>Description <textarea name="description" rows="3" maxlength="5000">{{ $video->description }}</textarea></label>
       <label>Credits <input name="credits" maxlength="1000" value="{{ $video->credits }}"></label>
       <label>Campaign <input name="campaign" maxlength="60" pattern="[A-Za-z0-9_-]+" value="{{ $video->campaign }}"></label>
       <label>Landing page <input name="landing_path" maxlength="255" value="{{ $video->landing_path }}"></label>
       <label>TikTok link <input name="tiktok_url" type="url" maxlength="500" value="{{ $video->tiktok_url }}"></label>
       <label>YouTube link <input name="youtube_url" type="url" maxlength="500" value="{{ $video->youtube_url }}"></label>
       <label>Sort order <input name="sort_order" type="number" value="{{ $video->sort_order }}"></label>
       <button type="submit">Save</button>
      </form>
     </details>
    </td>
   </tr>
  @endforeach
 </tbody></table>
 @endif

 <header class="md-xsection"><div><p class="md-eyebrow">PIN TO THE FRONT</p><h2>Slideshows and radio videos</h2>
  <p>Approved slideshows and ready radio videos already fill the row when there is room. Pin one to put it straight after the featured long videos.</p></div></header>
 @if($slideshows->isEmpty() && $radio->isEmpty())<p>No approved slideshows or ready radio videos.</p>@else
 <table class="md-signup-table"><tbody>
  @foreach($slideshows as $show)
   @php($subject = "slideshow:{$show->id}")
   <tr><td>Slideshow · {{ $show->label() }}: <a href="{{ $show->pageUrl() }}" target="_blank" rel="noopener">{{ data_get($show->facts, 'title', $show->title()) }}</a></td>
    <td><form method="POST" action="{{ route('admin.videos.pin') }}">@csrf<input type="hidden" name="subject" value="{{ $subject }}"><input type="hidden" name="pinned" value="{{ in_array($subject, $pinned, true) ? 0 : 1 }}"><button type="submit">{{ in_array($subject, $pinned, true) ? 'Unpin' : 'Pin' }}</button></form></td></tr>
  @endforeach
  @foreach($radio as $b)
   @php($subject = "radio:{$b->id}")
   <tr><td>Matchday Radio · {{ $b->editionLabel() }}: <a href="{{ route('radio.show', $b) }}" target="_blank" rel="noopener">{{ $b->title }}</a></td>
    <td><form method="POST" action="{{ route('admin.videos.pin') }}">@csrf<input type="hidden" name="subject" value="{{ $subject }}"><input type="hidden" name="pinned" value="{{ in_array($subject, $pinned, true) ? 0 : 1 }}"><button type="submit">{{ in_array($subject, $pinned, true) ? 'Unpin' : 'Pin' }}</button></form></td></tr>
  @endforeach
 </tbody></table>
 @endif
 @endif
</div>
@endsection
