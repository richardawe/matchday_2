@extends('layouts.public')

@section('content')
@include('partials.home.radio')
@if($arena ?? null)@include('partials.home-arena')@endif
<x-sponsor-slot slot="home" />

@if($premierLeagueMatches->isNotEmpty())
<section class="md-war-takeover"><div class="md-wrap"><header class="md-war-heading"><div><p>PREMIER LEAGUE · TODAY’S CAMPAIGN</p><h1>Choose your banner.<br><em>Enter the field.</em></h1></div><a href="{{ route('war.index') }}">ENTER MATCHDAY WAR →</a></header><div class="md-war-fixtures">@foreach($premierLeagueMatches as $fixture)<article class="md-war-clash"><small>{{ $fixture->match_date->format('H:i') }} · {{ Str::upper($fixture->status_display) }}</small><div class="md-war-armies"><div>@if(data_get($fixture->war_home,'image'))<img src="{{ data_get($fixture->war_home,'image') }}" alt="{{ $fixture->homeTeam?->display_name }} warrior">@endif<span>HOME BANNER</span><strong>{{ $fixture->homeTeam?->display_name }}</strong><em>{{ data_get($fixture->war_home,'faction') }}</em></div><b><i>{{ $fixture->home_score !== null ? $fixture->home_score.' — '.$fixture->away_score : 'VS' }}</i><span>THE FIELD AWAITS</span></b><div>@if(data_get($fixture->war_away,'image'))<img src="{{ data_get($fixture->war_away,'image') }}" alt="{{ $fixture->awayTeam?->display_name }} warrior">@endif<span>AWAY BANNER</span><strong>{{ $fixture->awayTeam?->display_name }}</strong><em>{{ data_get($fixture->war_away,'faction') }}</em></div></div><footer><a href="{{ route('matches.show',$fixture) }}">VIEW MATCH CENTRE</a><a href="{{ route('war.match',$fixture) }}#game">KICK OFF THE BATTLE</a></footer></article>@endforeach</div></div></section>
@elseif($hasFeaturedBlogs)
@php($hasFeaturedBlogs = false)
<section class="md-home-stories md-home-stories-first"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">LATEST NEWS</p><h1>Football stories worth your time.</h1></div><a href="{{ route('blogs.index') }}">All stories →</a></div><div class="md-story-grid">@foreach($featuredBlogs as $blog)<article class="md-story-card {{ $loop->first ? 'md-story-lead' : '' }}"><a href="{{ route('blogs.show', $blog) }}" class="md-story-image">@if($blog->featured_image)<img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}">@else<span>MATCHDAY<br>AFRICA</span>@endif</a><div><small>{{ $blog->formatted_published_date }} · {{ $blog->reading_time }}</small><h3><a href="{{ route('blogs.show', $blog) }}">{{ $blog->title }}</a></h3><p>{{ Str::limit($blog->excerpt, $loop->first ? 190 : 110) }}</p><a href="{{ route('blogs.show', $blog) }}">Read story →</a></div></article>@endforeach</div></div></section>
@endif

@include('partials.home.command')

@include('partials.home.focus')

<section class="md-pulse"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">MATCHDAY PULSE</p><h2>Now, next and just finished.</h2></div><a href="{{ route('matches.index') }}">Full match centre →</a></div>
    <div id="matchday-pulse" data-pulse-url="{{ route('home.pulse') }}">@include('partials.home-pulse')</div>
</div></section>

<section class="md-home-features"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">MORE THAN SCORES</p><h2>Follow the game. Join the conversation.</h2></div></div><div class="md-feature-grid">
    <a class="md-feature-card md-feature-war" href="{{ route('war.index') }}"><span>01 · MATCHDAY WAR</span><h3>Turn the fixture into a battle.</h3><p>Choose your side, rally supporters and experience football rivalry in real time.</p><b>Enter the arena →</b></a>
    <a class="md-feature-card md-feature-predict" href="{{ route('predictions.index') }}"><span>02 · PREDICTIONS</span><h3>Make your call before kickoff.</h3><p>Predict scores, compete with friends and prove how well you know the game.</p><b>Start predicting →</b></a>
</div></div></section>

@if($hasFeaturedBlogs)<section class="md-home-stories"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">FROM THE CONTINENT</p><h2>Football stories worth your time.</h2></div><a href="{{ route('blogs.index') }}">All stories →</a></div><div class="md-story-grid">
    @foreach($featuredBlogs as $blog)<article class="md-story-card {{ $loop->first ? 'md-story-lead' : '' }}"><a href="{{ route('blogs.show', $blog) }}" class="md-story-image">@if($blog->featured_image)<img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}">@else<span>MATCHDAY<br>AFRICA</span>@endif</a><div><small>{{ $blog->formatted_published_date }} · {{ $blog->reading_time }}</small><h3><a href="{{ route('blogs.show', $blog) }}">{{ $blog->title }}</a></h3><p>{{ Str::limit($blog->excerpt, $loop->first ? 190 : 110) }}</p><a href="{{ route('blogs.show', $blog) }}">Read story →</a></div></article>@endforeach
</div></div></section>@endif

@include('partials.home.leagues')

@include('partials.home.join')

@include('partials.home.ngr')
@endsection
