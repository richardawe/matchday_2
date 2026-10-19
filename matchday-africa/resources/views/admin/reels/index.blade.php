@extends('layouts.admin')
@section('title','Reels')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">REEL DESK</p><h1>Every match, twenty seconds.</h1><p>Premier League reels from the last three weeks. They render a few minutes after full time and are shared through the following week.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk">
 @if(session('success'))<p class="md-xguide">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-xguide md-xerror">{{ session('error') }}</p>@endif
 @if($renderer === 'off')<p class="md-xguide md-xerror">REEL_RENDERER is off: queued reels won't render until it is set.</p>@endif
 <details class="md-xcreate"><summary>Queue a round <b>＋</b></summary>
  <form method="POST" action="{{ route('admin.reels.queue-range') }}">@csrf
   <label><span>FROM (LAGOS DATE)</span><input name="from" type="date" required></label>
   <label><span>TO</span><input name="to" type="date" required></label>
   <button class="md-primary">Queue missing reels</button>
  </form>
 </details>
 <header class="md-xsection"><div><p class="md-eyebrow">FINISHED MATCHES</p><h2>Reels.</h2></div><span>{{ $matches->count() }} MATCHES</span></header>
 <div class="md-reeldesk">
 @forelse($matches as $m)
  @php
   $reel = data_get($m->metadata, 'reel', []);
   $scored = (int) $m->home_score + (int) $m->away_score;
   $events = (int) ($goals[$m->id] ?? 0);
  @endphp
  <article>
   @if(($reel['status'] ?? null) === 'ready' && $posters[$m->id])
    <a href="{{ route('matches.show', $m) }}#match-story"><img src="{{ route('matches.reel-poster', ['match' => $m, 'v' => $reel['ready_at'] ?? null]) }}" alt="Reel poster: {{ $m->homeTeam?->name }} v {{ $m->awayTeam?->name }}" loading="lazy" width="108" height="192"></a>
   @else<div class="md-reeldesk-empty">{{ strtoupper($reel['status'] ?? 'no reel') }}</div>@endif
   <div>
    <h3><a href="{{ route('matches.show', $m) }}">{{ $m->homeTeam?->name }} {{ $m->home_score }}–{{ $m->away_score }} {{ $m->awayTeam?->name }}</a></h3>
    <p class="md-xform"><time>{{ $m->match_date->format('D j M') }}</time> · <span class="md-xstatus {{ ($reel['status'] ?? '') === 'ready' ? 'sent' : (($reel['status'] ?? '') === 'failed' ? 'failed' : '') }}">{{ strtoupper($reel['status'] ?? 'none') }}</span>@if(!empty($reel['attempts'])) · {{ $reel['attempts'] }} {{ Str::plural('attempt', $reel['attempts']) }}@endif</p>
    @if($events < $scored)<p class="md-xerror">Only {{ $events }} of {{ $scored }} goals are in the data, so the reel is missing goal beats.</p>@endif
    @if(!empty($reel['error']))<p class="md-xerror">{{ Str::limit($reel['error'], 220) }}</p>@endif
   </div>
   <form method="POST" action="{{ route('admin.reels.queue', $m) }}">@csrf<button class="md-xsave">{{ ($reel['status'] ?? null) === 'ready' ? 'Re-render' : 'Render' }}</button></form>
  </article>
 @empty<div class="md-ops-empty">No finished Premier League matches in the last three weeks.</div>@endforelse
 </div>
</div>
@endsection
