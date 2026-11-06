@extends('layouts.admin')
@section('title','YouTube LIVE')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">YOUTUBE LIVE</p><h1>Matchday Live on YouTube.</h1><p>Connect the Matchday Africa channel once. The site then schedules a broadcast for every matchday, which goes live as soon as the picture from <a href="{{ route('stream.show') }}" target="_blank" rel="noopener">/stream</a> reaches the stream key below, and ends after the last match.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-notice md-flash-error">{{ session('error') }}</p>@endif

 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>).</p></section>
 @elseif(!$configured)
  <section class="md-xguide"><span>STEP 1: GOOGLE</span>
   <p>Before you can connect, Google needs to know about this site:</p>
   <ol>
    <li>In <a href="https://studio.youtube.com" target="_blank" rel="noopener">YouTube Studio</a>, open Create → Go live once and turn live streaming on (phone check; the first time can take up to 24 hours).</li>
    <li>In <a href="https://console.cloud.google.com" target="_blank" rel="noopener">Google Cloud</a>: make a project, enable <b>YouTube Data API v3</b>, set up the OAuth consent screen (External) and set it to <b>In production</b> (in "Testing" the connection expires after 7 days).</li>
    <li>Create an OAuth client ID of type <b>Web application</b> with this redirect URI: <code>{{ $redirect }}</code></li>
    <li>Add the client ID and secret as GitHub production secrets <code>YOUTUBE_CLIENT_ID</code> and <code>YOUTUBE_CLIENT_SECRET</code>, then run Server operations → <code>configure-youtube</code>.</li>
   </ol>
  </section>
 @elseif(!$account)
  <section class="md-xguide"><span>STEP 2: CONNECT</span>
   <p>Sign in with the Google account that owns the Matchday Africa channel. Google may warn that the app isn't verified: that's expected for your own channel (Advanced → Go to matchday.africa).</p>
   <p><a class="md-primary" href="{{ route('admin.youtube.connect') }}">Connect YouTube</a></p>
  </section>
 @else
  <header class="md-xsection"><div><p class="md-eyebrow">CONNECTED</p><h2>{{ $account->channel_title }}</h2></div>
   <form method="POST" action="{{ route('admin.youtube.disconnect') }}" onsubmit="return confirm('Disconnect YouTube? Scheduled broadcasts stay on the channel but nothing new is made.')">@csrf<button type="submit">Disconnect</button></form>
  </header>
  <table class="md-signup-table"><tbody>
   <tr><th>Channel</th><td><a href="https://www.youtube.com/channel/{{ $account->channel_id }}" target="_blank" rel="noopener">{{ $account->channel_id }}</a>@if($account->connectedBy) · connected by {{ $account->connectedBy->name }} {{ $account->created_at->diffForHumans() }}@endif</td></tr>
   <tr><th>Stream</th><td>{{ $health ? ucfirst($health) : 'Unknown' }} <small>(active = YouTube is receiving the picture)</small></td></tr>
   <tr><th>Server URL</th><td><code>{{ $account->ingest_url }}</code></td></tr>
   <tr><th>Stream key</th><td><details><summary>Show the key (keep it private: anyone with it can stream to the channel)</summary><input readonly value="{{ $account->stream_key }}" aria-label="Stream key" onclick="this.select()" style="width:100%;max-width:32rem;font-family:monospace"></details></td></tr>
   <tr><th>Automatic</th><td>@if($auto)On: a broadcast is made for each matchday up to 48 hours ahead, as <b>{{ $privacy }}</b>.@else Off: schedule matchdays with the buttons below, or set <code>YOUTUBE_AUTO=true</code> (Server operations → <code>configure-youtube</code>, switch on).@endif</td></tr>
   <tr><th>Stream page</th><td>@if($streamPage)<a href="{{ route('stream.show') }}" target="_blank" rel="noopener">/stream</a> is on.@else <code>/stream</code> is off (Server operations → <code>configure-stream</code>).@endif Point OBS (Browser source, 1080×1920) at it and stream to the key above.</td></tr>
  </tbody></table>

  <header class="md-xsection"><div><p class="md-eyebrow">NEXT 48 HOURS</p><h2>Matchdays</h2></div>
   <form method="POST" action="{{ route('admin.youtube.test') }}">@csrf<button type="submit">Make a private 30-minute test broadcast</button></form>
  </header>
  @if($windows->isEmpty())<p>No matches in the streamed competitions in the next 48 hours.</p>@else
  <table class="md-signup-table"><thead><tr><th>Window (Lagos)</th><th>Matches</th><th>Broadcast</th></tr></thead><tbody>
   @foreach($windows as $w)
    @php($made = $broadcasts->firstWhere('window_key', $w['key']))
    <tr><td>{{ $w['start']->copy()->setTimezone('Africa/Lagos')->format('D j M H:i') }}–{{ $w['end']->copy()->setTimezone('Africa/Lagos')->format('H:i') }}</td>
     <td>{{ $w['matches']->map(fn ($m) => $m->homeTeam?->name.' v '.$m->awayTeam?->name)->implode(', ') }}</td>
     <td>@if($made)<a href="{{ $made->watchUrl() }}" target="_blank" rel="noopener">{{ ucfirst($made->status) }}</a>@else<form method="POST" action="{{ route('admin.youtube.schedule') }}">@csrf<input type="hidden" name="window" value="{{ $w['key'] }}"><button class="md-primary" type="submit">Schedule now</button></form>@endif</td></tr>
   @endforeach
  </tbody></table>@endif

  <header class="md-xsection"><div><p class="md-eyebrow">MADE</p><h2>Broadcasts</h2></div></header>
  @if($broadcasts->isEmpty())<p>None yet.</p>@else
  <table class="md-signup-table"><thead><tr><th>Title</th><th>When (Lagos)</th><th>Privacy</th><th>Status</th><th></th></tr></thead><tbody>
   @foreach($broadcasts as $b)
    <tr><td>{{ $b->title }}</td><td>{{ $b->starts_at->copy()->setTimezone('Africa/Lagos')->format('D j M H:i') }}–{{ $b->ends_at->copy()->setTimezone('Africa/Lagos')->format('H:i') }}</td><td>{{ $b->privacy }}</td>
     <td>{{ ucfirst($b->status) }}@if($b->error)<br><small>{{ \Illuminate\Support\Str::limit($b->error, 160) }}</small>@endif</td>
     <td><a href="{{ $b->watchUrl() }}" target="_blank" rel="noopener">Watch</a> · <a href="{{ $b->studioUrl() }}" target="_blank" rel="noopener">Studio</a></td></tr>
   @endforeach
  </tbody></table>@endif
 @endif
</div>
@endsection
