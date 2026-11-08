@extends('layouts.public')

{{-- The reels-first homepage (config/home.php): live scores, Watch live, Beat the Griot, reels hero, next and results, news, players, play. --}}
@section('content')
@include('partials.home.radio-bar')
@include('partials.home.live-now')
@include('partials.home.watch-live')
@include('partials.home.griot-challenge')
@php($featured = $reels['featured'])
<section class="md-reels" id="reels-hero" aria-labelledby="reels-title">
    <div class="md-wrap md-reels-grid">
        @php($slides = $featured ? collect([$featured])->concat($reels['rail']) : collect())
        @php($cards = !$featured ? $board->take(6) : collect())
        {{-- The dots and the swipe hint sit in a strip under the player, never over the reel: its
             caption box and "matchday.africa · told by the Griot" line are drawn into the video. --}}
        <div class="md-reels-stage">
        <div class="md-reels-player {{ $featured ? '' : 'is-cards' }}" data-reels-player>
            @if($featured || $cards->isNotEmpty())
                {{-- Swipe between reels (or, before any reels, this week's fixture cards). The reel on screen plays muted by itself. --}}
                <div class="md-reels-track" data-reels-track tabindex="0" role="region" aria-roledescription="carousel" aria-label="{{ $featured ? 'Reels: swipe for the next match' : 'This week\'s fixtures: swipe for more' }}">
                    @foreach($featured ? $slides : $cards as $i => $slide)
                        @if($featured)
                            <figure class="md-reels-slide" data-reel-slide data-reel-title="{{ $slide['title'] }}" data-reel-link="{{ $slide['link'] }}" data-reel-share="{{ \App\Support\ShareLink::tagged($slide['page'], 'whatsapp', 'reel') }}" aria-label="{{ $i + 1 }} of {{ $slides->count() }}">
                                <video muted playsinline loop preload="{{ $i === 0 ? 'metadata' : 'none' }}" @if($slide['poster']) poster="{{ $slide['poster'] }}" @endif src="{{ $slide['video'] }}" aria-label="{{ $slide['title'] }}: the match reel"></video>
                            </figure>
                        @else
                            <a class="md-reels-slide" data-reel-slide href="{{ route('matches.show', $slide['match']) }}" aria-label="{{ $slide['title'] }}, kick-off {{ $slide['kickoff']->format('D H:i') }} WAT ({{ $i + 1 }} of {{ $cards->count() }})">
                                <img class="md-reels-fallback" src="{{ $slide['card'] }}" alt="" @if($i) loading="lazy" @endif width="1200" height="675">
                            </a>
                        @endif
                    @endforeach
                </div>
                @if($featured)
                    <button type="button" class="md-reels-tap" data-reels-tap hidden><span aria-hidden="true">▶</span> Tap to play</button>
                @endif
                @if(($featured ? $slides : $cards)->count() > 1)
                    <button type="button" class="md-reels-nav is-prev" data-reels-prev aria-label="Previous">‹</button>
                    <button type="button" class="md-reels-nav is-next" data-reels-next aria-label="Next">›</button>
                @endif
            @endif
        </div>
        @if($featured || $cards->count() > 1)
            <div class="md-reels-controls">
                @if($featured)<button type="button" class="md-reels-sound" data-reels-sound aria-pressed="false">Sound on</button>@endif
                @if(($featured ? $slides : $cards)->count() > 1)
                    <div class="md-reels-dots" aria-hidden="true">@foreach($featured ? $slides : $cards as $slide)<i @class(['is-on' => $loop->first])></i>@endforeach</div>
                    <span class="md-reels-hint" data-reels-hint aria-hidden="true">Swipe ⇆</span>
                @endif
            </div>
        @endif
        </div>
        <div class="md-reels-copy">
            <p class="md-eyebrow">THIS WEEK'S REELS</p>
            <h1 id="reels-title">Every match, <em>told in twenty seconds.</em></h1>
            @if($featured)
                <p class="md-reels-now"><span data-reels-title>{{ $featured['title'] }}</span></p>
                <div class="md-home-actions">
                    <a class="md-primary" data-reels-link href="{{ $featured['link'] }}">Full story and stats</a>
                    <a class="md-secondary" data-reels-share href="https://wa.me/?text={{ rawurlencode('Watch '.$featured['title'].' in 20 seconds '.\App\Support\ShareLink::tagged($featured['page'], 'whatsapp', 'reel')) }}" target="_blank" rel="noopener">Share on WhatsApp</a>
                </div>
            @else
                <p class="md-reels-now">Every Premier League match gets a 20-second reel minutes after the final whistle. This round's reels appear here.</p>
                <div class="md-home-actions"><a class="md-primary" href="{{ route('matches.index') }}">See this week's fixtures</a></div>
            @endif
            @if($reels['rail']->isNotEmpty())
                <div class="md-reels-rail" role="list" aria-label="More reels">
                    @foreach($reels['rail'] as $reel)
                        <button type="button" role="listitem" class="md-reels-thumb" data-reel-goto="{{ $loop->iteration }}">
                            @if($reel['poster'])<img src="{{ $reel['poster'] }}" alt="" loading="lazy" width="108" height="192">@endif
                            <span>{{ $reel['title'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
<x-whatsapp-channel context="home" />
<x-sponsor-slot slot="home" />

@include('partials.home.command')

{{-- Live scores lead the page (live-now), so this block shows only what's next and what just finished. --}}
<section class="md-pulse md-pulse-no-live"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">TODAY</p><h2>Next and just finished.</h2></div><a href="{{ route('matches.index') }}">All matches →</a></div>
    <div id="matchday-pulse" data-pulse-url="{{ route('home.pulse') }}">@include('partials.home-pulse')</div>
</div></section>


@if($hasFeaturedBlogs)
<section class="md-home-stories md-news-compact"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">LATEST NEWS</p><h2>Football stories worth your time.</h2></div><a href="{{ route('blogs.index') }}">All stories →</a></div>
    <div class="md-news-list">
        @foreach($featuredBlogs->take(3) as $blog)
            <article><a href="{{ route('blogs.show', $blog) }}">@if($blog->imageUrl())<img src="{{ $blog->featured_image_url }}" alt="" loading="lazy">@endif<div><small>{{ $blog->published_at?->format('j M') }}</small><h3>{{ $blog->title }}</h3><p>{{ Str::limit(strip_tags((string) $blog->excerpt), 120) }}</p></div></a></article>
        @endforeach
    </div>
</div></section>
@endif

@if($africanPlayersInFocus->isNotEmpty())@include('partials.home.focus')@endif

<section class="md-play"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">PLAY</p><h2>Watch, call it, pick your side.</h2></div></div>
    <div class="md-feature-grid">
        @if(\App\Support\StreamLink::enabled())<a class="md-feature-card md-feature-live" href="{{ \App\Support\StreamLink::url() }}"><span>MATCHDAY LIVE</span><h3>Every goal as it goes in.</h3><p>Live scores, goals, red cards and the Griot's call on one screen, all matchday long.</p><b>Watch live →</b></a>@endif
        <a class="md-feature-card md-feature-predict" href="{{ route('predictions.index') }}"><span>BEAT THE GRIOT</span><h3>Three matches. Beat the Griot.</h3><p>The Griot calls three scores every round. Score more points and you could win {{ config('griot_challenge.prize') }}.</p><b>Take on the Griot →</b></a>
        <a class="md-feature-card md-feature-war" href="{{ \App\Support\WarLinks::home() }}"><span>MATCHDAY WAR</span><h3>Turn the fixture into a battle.</h3><p>Choose your side and rally supporters.</p><b>Enter Matchday War →</b></a>
    </div>
</div></section>

@include('partials.home.join')
@include('partials.home.ngr')
@endsection
