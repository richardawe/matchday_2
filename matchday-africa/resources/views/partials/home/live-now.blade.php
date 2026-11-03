{{-- Live scores first: when a match is on, its score leads the homepage. Filled again by the
     pulse refresh (HomeController::pulse, resources/js/app.js), so a match that kicks off while
     the page is open appears without a reload. --}}
<section class="md-live-now" id="home-live" aria-label="Live scores"{{ $liveMatches->isEmpty() ? ' hidden' : '' }}>
    @if($liveMatches->isNotEmpty())
    <div class="md-wrap">
        <div class="md-live-now-head"><h2><span class="md-pulse-dot"></span>LIVE NOW</h2><small>{{ $liveMatches->count() }} {{ Str::plural('match', $liveMatches->count()) }} · updated {{ now('Africa/Lagos')->format('H:i') }} WAT</small></div>
        <div class="md-live-now-grid">
            @foreach($liveMatches->take(6) as $match)@include('partials.home-match-card', ['match' => $match, 'mode' => 'live'])@endforeach
        </div>
        @if($liveMatches->count() > 6)<a class="md-pulse-more" href="{{ route('matches.index', ['filter' => 'live']) }}">All {{ $liveMatches->count() }} live matches →</a>@endif
    </div>
    @endif
</section>
