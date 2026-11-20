{{-- The live front page's one hero (App\Services\FrontPage::hero), never empty: a live match, the stream
     or Matchday Radio on air, breaking news, the newest long video, the newest article, else the way
     into the matches. The pulse sends it again (hero, hero_key) and resources/js/app.js swaps it when
     the key changes and nothing in it is playing. --}}
@php($kind = $hero['kind'])
<section class="md-front-hero md-front-hero--{{ $kind }}" id="home-hero" data-hero-kind="{{ $kind }}" data-hero-key="{{ $heroKey }}" aria-label="Top story">
@switch($kind)
@case('live')
    @php($lead = $hero['match'])
    @php($moods = \App\Support\ArmyMoods::forMatch($lead))
    @php($goals = $lead->relationLoaded('events') ? $lead->events : collect())
    <div class="md-front-live" id="home-live" aria-label="Live scores">
        <div class="md-wrap">
            <p class="md-front-tag"><span class="md-pulse-dot"></span>LIVE NOW · {{ $lead->league?->name ?? 'Football' }}</p>
            <a class="md-front-score" href="{{ route('matches.show', $lead) }}">
                <span class="md-front-side">@if($moods['home'])<img class="md-mood{{ $moods['home']['logo'] ? ' is-logo' : '' }}" src="{{ $moods['home']['url'] }}" alt="{{ $moods['home']['alt'] }}" width="88" height="88">@endif<b>{{ $lead->homeTeam?->display_name ?? 'Home' }}</b></span>
                <span class="md-front-result"><strong>{{ $lead->home_score ?? 0 }}–{{ $lead->away_score ?? 0 }}</strong><em>{{ $lead->live_label }}</em></span>
                <span class="md-front-side">@if($moods['away'])<img class="md-mood{{ $moods['away']['logo'] ? ' is-logo' : '' }}" src="{{ $moods['away']['url'] }}" alt="{{ $moods['away']['alt'] }}" width="88" height="88">@endif<b>{{ $lead->awayTeam?->display_name ?? 'Away' }}</b></span>
            </a>
            @if($goals->isNotEmpty())
                <p class="md-front-scorers">@foreach($goals as $goal){{ $goal->player_name ?: 'Goal' }} {{ $goal->minute }}'@if($goal->type === 'penalty_goal') (pen)@elseif($goal->type === 'own_goal') (og)@endif{{ $loop->last ? '' : ' · ' }}@endforeach</p>
            @endif
            <a class="md-front-cta" href="{{ route('matches.show', $lead) }}">Follow it live →</a>
            @if($liveMatches->count() > 1)
                <div class="md-front-more">
                    <p class="md-front-more-head">Also live · {{ $liveMatches->count() - 1 }} more</p>
                    <div class="md-live-now-grid">@foreach($liveMatches->slice(1)->take(6) as $match)@include('partials.home-match-card', ['match' => $match, 'mode' => 'live'])@endforeach</div>
                    @if($liveMatches->count() > 7)<a class="md-pulse-more" href="{{ route('matches.index', ['filter' => 'live']) }}">All {{ $liveMatches->count() }} live matches →</a>@endif
                </div>
            @endif
        </div>
    </div>
    @break
@case('stream')
    <div class="md-wrap md-front-body">
        <div class="md-front-copy">
            <p class="md-front-tag"><span class="md-pulse-dot"></span>On air · Matchday Live</p>
            <h2 class="md-front-title">Matchday Live is on air now</h2>
            <p class="md-front-text">Every score, goal and red card with the Griot's call, on one screen.</p>
            <a class="md-front-cta" href="{{ $hero['url'] }}" target="_blank" rel="noopener">Watch live ▶</a>
        </div>
    </div>
    @break
@case('radio')
    @php($bulletin = $hero['bulletin'])
    <div class="md-wrap md-front-body" id="matchday-radio" data-radio-player data-share-url="{{ route('radio.show', $bulletin) }}" data-share-title="Matchday Africa · {{ $bulletin->title }}">
        @if($bulletin->posterUrl())<a class="md-front-media is-portrait" href="{{ route('radio.show', $bulletin) }}"><img src="{{ $bulletin->posterUrl() }}" alt="" width="270" height="480"></a>@endif
        <div class="md-front-copy">
            <p class="md-front-tag"><span class="md-pulse-dot"></span>On air · Matchday Radio</p>
            <h2 class="md-front-title"><a class="md-front-title-link" href="{{ route('radio.show', $bulletin) }}">{{ $bulletin->title }}</a></h2>
            <p class="md-front-text">{{ $bulletin->editionLabel() }} · {{ $bulletin->recorded_at?->copy()->timezone('Africa/Lagos')->format('H:i') }} WAT · {{ $bulletin->duration_label }}</p>
            <audio preload="none" src="{{ $bulletin->audioUrl() }}" aria-label="Listen to {{ $bulletin->title }}" controls></audio>
            <div class="md-front-actions" data-radio-controls hidden>
                <button type="button" class="md-front-cta" data-radio-play aria-label="Listen now" aria-pressed="false"><span data-radio-icon aria-hidden="true">▶</span> <span data-radio-label>Listen now</span></button>
                <button type="button" class="md-front-ghost" data-radio-share aria-label="Share this edition">Share</button>
            </div>
            <div class="md-front-progress" data-radio-progress hidden><input type="range" min="0" max="{{ $bulletin->duration_seconds }}" value="0" step="1" aria-label="Playback position" data-radio-seek><span data-radio-elapsed>0:00</span></div>
            <p class="md-front-feedback" data-radio-feedback role="status" aria-live="polite"></p>
        </div>
    </div>
    @break
@case('breaking')
    @include('partials.breaking', ['item' => $hero['item'], 'variant' => 'banner'])
    @break
@case('video')
    @php($video = $hero['video'])
    <div class="md-wrap md-front-body">
        <div class="md-front-media is-portrait">
            <video controls playsinline preload="none" @if($video->posterUrl()) poster="{{ $video->posterUrl() }}" @endif src="{{ $video->videoUrl() }}" aria-label="{{ $video->title }}" width="270" height="480"></video>
        </div>
        <div class="md-front-copy">
            <p class="md-front-tag">New video · {{ $video->typeLabel() }}@if($video->lengthLabel()) · {{ $video->lengthLabel() }}@endif</p>
            <h2 class="md-front-title"><a class="md-front-title-link" href="{{ $video->pageUrl() }}">{{ $video->title }}</a></h2>
            @if($summary = $video->summary())<p class="md-front-text">{{ $summary }}</p>@endif
            <a class="md-front-cta" href="{{ $video->pageUrl() }}">Watch ▶</a>
        </div>
    </div>
    @break
@case('article')
    @php($blog = $hero['blog'])
    <div class="md-wrap md-front-body">
        @if($blog->imageUrl())<a class="md-front-media" href="{{ route('blogs.show', $blog) }}"><img src="{{ $blog->featured_image_url }}" alt="" width="640" height="360"></a>@endif
        <div class="md-front-copy">
            <p class="md-front-tag">Latest story · {{ $blog->published_at?->copy()->timezone('Africa/Lagos')->format('j M, H:i') }}</p>
            <h2 class="md-front-title"><a class="md-front-title-link" href="{{ route('blogs.show', $blog) }}">{{ $blog->title }}</a></h2>
            @if($blog->excerpt)<p class="md-front-text">{{ Str::limit(strip_tags((string) $blog->excerpt), 220) }}</p>@endif
            <a class="md-front-cta" href="{{ route('blogs.show', $blog) }}">Read the story →</a>
        </div>
    </div>
    @break
@default
    <div class="md-wrap md-front-body">
        <div class="md-front-copy">
            <p class="md-front-tag">Matchday Africa</p>
            <h2 class="md-front-title">Scores, reels and the Griot, all matchday long</h2>
            <a class="md-front-cta" href="{{ route('matches.index') }}">See today's matches →</a>
        </div>
    </div>
@endswitch
</section>
