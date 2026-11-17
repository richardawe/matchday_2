<div style="margin-bottom:.8rem">
 <b>{{ strtoupper($story->lang) }}</b> <code>{{ $story->queueId() }}</code>:
 @switch($story->status)
  @case('ready') ready, {{ rtrim(rtrim(number_format((float) $story->duration_seconds, 1), '0'), '.') }} s @break
  @case('rendering') rendering… @break
  @case('pending') waiting to render @break
  @default failed ({{ $story->attempts }} of {{ config('story.max_attempts') }} attempts)
 @endswitch
 @if($story->error)<br><small class="md-flash-error">{{ \Illuminate\Support\Str::limit($story->error, 200) }}</small>@endif
 @if($story->videoUrl())
  <br><video controls preload="none" playsinline @if($story->coverUrl()) poster="{{ $story->coverUrl() }}" @endif src="{{ $story->videoUrl(true) }}" style="width:9rem;aspect-ratio:9/16;background:#000;margin-top:.4rem"></video>
  <br><a href="{{ $story->videoUrl() }}" target="_blank" rel="noopener">MP4</a>@if($story->coverUrl()) · <a href="{{ $story->coverUrl() }}" target="_blank" rel="noopener">Cover</a>@endif
 @endif
 @if(!empty($story->photo['credit']))<br><small>Photo: {{ $story->photo['credit'] }}</small>@endif
 @if($story->rendered_at)<br><small>Rendered {{ $story->rendered_at->setTimezone('Europe/London')->format('D j M H:i T') }}</small>@endif
</div>
