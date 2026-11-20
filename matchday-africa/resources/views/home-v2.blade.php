@extends('layouts.public')

{{-- The live front page (config/home.php, App\Services\FrontPage): one hero chosen in priority order, "Updated HH:MM",
     New on Matchday, Watch live, Beat the Griot, reels, As seen on TikTok, next and results, African-first news, players, play. --}}
@section('content')
<div class="md-front" id="home-front">
@include('partials.home.updated')
@include('partials.home.hero')
</div>
@if($hero['kind'] !== 'breaking')@include('partials.breaking', ['variant' => 'banner'])@endif
@if($hero['kind'] !== 'radio')@include('partials.home.radio-bar')@endif
@include('partials.home.fresh')
@if($hero['kind'] !== 'stream')@include('partials.home.watch-live')@endif
@include('partials.home.griot-challenge')
@include('partials.home.reels-strip')
<div class="md-wrap">@include('partials.featured-videos')</div>
<x-whatsapp-channel context="home" />
<x-sponsor-slot slot="home" />

@include('partials.home.command')

{{-- Live scores lead the page (live-now), so this block shows only what's next and what just finished. --}}
<section class="md-pulse md-pulse-no-live"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">TODAY</p><h2>Next and just finished.</h2></div><a href="{{ route('matches.index') }}">All matches →</a></div>
    <div id="matchday-pulse" data-pulse-url="{{ route('home.pulse') }}">@include('partials.home-pulse')</div>
</div></section>


{{-- African stories first, each group newest first, from the last 7 days when there are any (FrontPage::news). --}}
@if($news->isNotEmpty())
<section class="md-home-stories md-news-compact"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">LATEST NEWS</p><h2>Football stories worth your time.</h2></div><a href="{{ route('blogs.index') }}">All stories →</a></div>
    <div class="md-news-list" id="home-news">
        @foreach($news as $blog)
            <article data-news-id="{{ $blog->id }}"@if($blog->is_african) data-african @endif><a href="{{ route('blogs.show', $blog) }}">@if($image = $blog->getAttribute('front_image'))<img src="{{ $image }}" alt="" loading="lazy">@elseif($blog->getAttribute('front_card'))<span class="md-news-fallback" data-fallback-card aria-hidden="true">Matchday Africa news</span>@endif<div><small>@if($blog->is_african)Africa · @endif{{ $blog->published_at?->copy()->timezone('Africa/Lagos')->format('j M') }}</small><h3>{{ $blog->title }}</h3><p>{{ Str::limit(strip_tags((string) $blog->excerpt), 120) }}</p></div></a></article>
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
