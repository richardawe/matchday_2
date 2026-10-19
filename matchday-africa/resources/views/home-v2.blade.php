@extends('layouts.public')

{{-- The reels-first homepage (config/home.php): reels hero, today strip, matchweek board, news, play, leagues. --}}
@section('content')
@php($featured = $reels['featured'])
<section class="md-reels" id="reels-hero" aria-labelledby="reels-title">
    <div class="md-wrap md-reels-grid">
        <div class="md-reels-player">
            @if($featured)
                <video id="reels-video" muted playsinline loop preload="none" poster="{{ $featured['poster'] }}" src="{{ $featured['video'] }}" aria-label="{{ $featured['title'] }}: the 20-second reel"></video>
                <button type="button" class="md-reels-sound" data-reels-sound aria-pressed="false">Sound on</button>
            @elseif($board->isNotEmpty())
                <img class="md-reels-fallback" src="{{ $board->first()['card'] }}" alt="{{ $board->first()['title'] }}, kick-off {{ $board->first()['kickoff']->format('H:i') }} WAT">
            @endif
        </div>
        <div class="md-reels-copy">
            <p class="md-eyebrow">THIS WEEK'S REELS</p>
            <h1 id="reels-title">Every match, <em>told in twenty seconds.</em></h1>
            @if($featured)
                <p class="md-reels-now"><span data-reels-title>{{ $featured['title'] }}</span></p>
                <div class="md-home-actions">
                    <a class="md-primary" data-reels-link href="{{ $featured['link'] }}">Full story and stats</a>
                    <a class="md-secondary" data-reels-share href="https://wa.me/?text={{ rawurlencode('Watch '.$featured['title'].' in 20 seconds '.$featured['link']) }}" target="_blank" rel="noopener">Share on WhatsApp</a>
                </div>
            @else
                <p class="md-reels-now">Every Premier League match gets a 20-second reel minutes after the final whistle. This round's reels appear here.</p>
                <div class="md-home-actions"><a class="md-primary" href="{{ route('matches.index') }}">See this week's fixtures</a></div>
            @endif
            @if($reels['rail']->isNotEmpty())
                <div class="md-reels-rail" role="list" aria-label="More reels">
                    @foreach($reels['rail'] as $reel)
                        <button type="button" role="listitem" class="md-reels-thumb" data-reel-video="{{ $reel['video'] }}" data-reel-poster="{{ $reel['poster'] }}" data-reel-title="{{ $reel['title'] }}" data-reel-link="{{ $reel['link'] }}">
                            @if($reel['poster'])<img src="{{ $reel['poster'] }}" alt="" loading="lazy" width="108" height="192">@endif
                            <span>{{ $reel['title'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
<x-sponsor-slot slot="home" />

@include('partials.home.command')

<section class="md-pulse"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">TODAY</p><h2>Live, next and just finished.</h2></div><a href="{{ route('matches.index') }}">Full match centre →</a></div>
    <div id="matchday-pulse" data-pulse-url="{{ route('home.pulse') }}">@include('partials.home-pulse')</div>
</div></section>

@if($board->isNotEmpty())
<section class="md-board"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">MATCHWEEK BOARD</p><h2>Who wins? Call it before kick-off.</h2></div><a href="{{ route('predictions.index') }}">All predictions →</a></div>
    <div class="md-board-grid">
        @foreach($board as $item)
            <a class="md-board-card" href="{{ route('matches.show', $item['match']) }}#prediction-arena">
                <img src="{{ $item['card'] }}" alt="{{ $item['title'] }}, {{ $item['match']->league?->name }}" loading="lazy" width="1200" height="675">
                <span><b>{{ $item['title'] }}</b><small>{{ strtoupper($item['kickoff']->format('D j M · H:i')) }} WAT</small><i>Call it →</i></span>
            </a>
        @endforeach
    </div>
</div></section>
@endif

@if($hasFeaturedBlogs)
<section class="md-home-stories md-news-compact"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">LATEST NEWS</p><h2>Football stories worth your time.</h2></div><a href="{{ route('blogs.index') }}">All stories →</a></div>
    <div class="md-news-list">
        @foreach($featuredBlogs->take(3) as $blog)
            <article><a href="{{ route('blogs.show', $blog) }}">@if($blog->featured_image)<img src="{{ $blog->featured_image_url }}" alt="" loading="lazy">@endif<div><small>{{ $blog->published_at?->format('j M') }}</small><h3>{{ $blog->title }}</h3><p>{{ Str::limit(strip_tags((string) $blog->excerpt), 120) }}</p></div></a></article>
        @endforeach
    </div>
</div></section>
@endif

@if($africanPlayersInFocus->isNotEmpty())@include('partials.home.focus')@endif

<section class="md-play"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">PLAY</p><h2>Make your call. Pick your army.</h2></div></div>
    <div class="md-feature-grid">
        <a class="md-feature-card md-feature-predict" href="{{ route('predictions.index') }}"><span>PREDICTIONS</span><h3>Make your call before kick-off.</h3><p>Predict scores, compete with friends and prove how well you know the game.</p><b>Start predicting →</b></a>
        <a class="md-feature-card md-feature-war" href="{{ route('war.index') }}"><span>MATCHDAY WAR</span><h3>Turn the fixture into a battle.</h3><p>Choose your side and rally supporters. @if($arena)Today's headline match is in the arena.@endif</p><b>Enter Matchday War →</b></a>
    </div>
    @if($arena)<p class="md-play-arena"><a href="{{ route('war.arena') }}">Watch the arena: {{ $arena['home']['army'] ?? '' }} v {{ $arena['away']['army'] ?? '' }} →</a></p>@endif
</div></section>

@include('partials.home.leagues')
@include('partials.home.join')
@include('partials.home.ngr')
@endsection
