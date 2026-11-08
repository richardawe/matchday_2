{{-- One way into the live stream (STREAM_PAGE): YouTube while the matchday broadcast is live, else /stream. --}}
@if(\App\Support\StreamLink::enabled())
@php($onAir = \App\Support\StreamLink::onYouTube() || $liveMatches->isNotEmpty())
<section class="md-watch-live{{ $onAir ? ' is-live' : '' }}" aria-label="Matchday Live">
    <div class="md-wrap">
        <a class="md-watch-live-link" href="{{ \App\Support\StreamLink::url() }}" @if(\App\Support\StreamLink::onYouTube()) target="_blank" rel="noopener" @endif>
            <span class="md-watch-live-tag">@if($onAir)<span class="md-pulse-dot"></span>@endif MATCHDAY LIVE</span>
            <strong>{{ $onAir ? 'Watch every goal as it goes in' : 'Live scores, goals and the Griot, on one screen' }}</strong>
            <b>Watch live ▶</b>
        </a>
    </div>
</section>
@endif
