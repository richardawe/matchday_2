@extends('layouts.admin')
@section('title','YouTube Shorts')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">YOUTUBE SHORTS</p><h1>Every reel on YouTube.</h1><p>Each new reel goes up as a public Short on the Matchday Africa channel. An upload costs 1,600 of YouTube's 10,000 daily quota units, so at most {{ $daily }} go up a day; the rest wait for the next day. <a href="{{ route('admin.youtube.index') }}">YouTube settings</a></p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-notice md-flash-error">{{ session('error') }}</p>@endif

 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>).</p></section>
 @else
  <table class="md-signup-table"><tbody>
   <tr><th>Uploads</th><td>
    @if($enabled)On, to <b>{{ $account->channel_title }}</b>.
    @elseif(!$switchedOn)Off: set the GitHub variable <code>YOUTUBE_UPLOADS</code> to <code>true</code> and deploy (or set <code>YOUTUBE_UPLOADS=true</code> in the server .env).
    @else Waiting for the channel: <a href="{{ route('admin.youtube.connect') }}">connect YouTube</a> with the Matchday Africa channel.@endif
   </td></tr>
   <tr><th>Today</th><td>{{ $used }} of {{ $daily }} uploads used{{ $left === 0 ? ' (quota used up)' : '' }}; the quota day starts again at {{ $reset->copy()->setTimezone('Africa/Lagos')->format('D H:i') }} Lagos.</td></tr>
  </tbody></table>

  <header class="md-xsection"><div><p class="md-eyebrow">LAST 14 DAYS</p><h2>Reels</h2></div></header>
  @if($matches->isEmpty())<p>No reels yet.</p>@else
  <table class="md-signup-table"><thead><tr><th>Match</th><th>Played (Lagos)</th><th>YouTube</th><th></th></tr></thead><tbody>
   @foreach($matches as $m)
    @php($status = $m->youtube_video_id ? 'uploaded' : ($m->youtube_status ?: 'not queued'))
    <tr>
     <td><a href="{{ url('/matches/'.$m->id) }}" target="_blank" rel="noopener">{{ $m->homeTeam?->name }} {{ (int) $m->home_score }}-{{ (int) $m->away_score }} {{ $m->awayTeam?->name }}</a><br><small>#{{ $m->id }} · {{ $m->league?->name }}</small></td>
     <td>{{ \Carbon\Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone('Africa/Lagos')->format('D j M H:i') }}</td>
     <td>
      @if($m->youtube_video_id)
       <a href="https://youtube.com/shorts/{{ $m->youtube_video_id }}" target="_blank" rel="noopener">Uploaded</a>@if($m->youtube_uploaded_at) <small>{{ $m->youtube_uploaded_at->diffForHumans() }}</small>@endif
      @else
       {{ ucfirst($status) }}@if($m->youtube_attempts) <small>(attempt {{ $m->youtube_attempts }})</small>@endif
       @if($status === 'pending' && $m->youtube_next_at && $m->youtube_next_at->isFuture())<br><small>next try {{ $m->youtube_next_at->copy()->setTimezone('Africa/Lagos')->format('D H:i') }} Lagos</small>@endif
      @endif
      @if($m->youtube_error)<br><small>{{ \Illuminate\Support\Str::limit($m->youtube_error, 220) }}</small>@endif
     </td>
     <td>
      @if(!$m->youtube_video_id && $hasReel($m) && $status !== 'uploading')
       <form method="POST" action="{{ route('admin.youtube.uploads.retry', $m) }}">@csrf<button type="submit" @if($status === 'failed') class="md-primary" @endif>{{ $status === 'not queued' ? 'Upload' : 'Retry upload' }}</button></form>
      @endif
     </td>
    </tr>
   @endforeach
  </tbody></table>@endif
 @endif
</div>
@endsection
