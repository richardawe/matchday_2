@extends('layouts.public')

@section('content')
<x-sponsor-slot slot="home" />

@auth
<section class="md-command"><div class="md-wrap">
    <div class="md-command-head"><div><p class="md-eyebrow">YOUR MATCHDAY</p><h1>Welcome back, {{ Str::before(auth()->user()->name, ' ') }}.</h1><p>Fixtures, predictions and stories from the clubs you follow.</p></div><a class="md-secondary" href="{{ route('onboarding') }}">{{ $followedTeams->isEmpty()?'Choose clubs':'Edit clubs' }}</a></div>
    @if($followedTeams->isEmpty())<a href="{{ route('onboarding') }}" class="md-onboard"><strong>Make Matchday yours.</strong><span>Choose the teams you follow →</span></a>@else<div class="md-following">@foreach($followedTeams as $team)<span>@if($team->logo_url)<img src="{{ $team->logo_url }}" alt="">@endif{{ $team->display_name }}</span>@endforeach</div>@endif
    <div class="md-command-grid"><div class="md-command-main"><div class="md-section-title"><p>YOUR NEXT MATCHES</p><a href="{{ route('matches.index') }}">All matches</a></div>
    @forelse($personalMatches as $fixture)<a class="md-fixture" href="{{ route('matches.show',$fixture) }}"><small>{{ $fixture->league?->name }} · {{ $fixture->match_date->isToday()?'TODAY':strtoupper($fixture->match_date->format('D j M')) }}</small><div><span>{{ $fixture->homeTeam?->name }}</span><b>{{ $fixture->home_score!==null?$fixture->home_score:$fixture->match_date->format('H:i') }} — {{ $fixture->away_score!==null?$fixture->away_score:'' }}</b><span>{{ $fixture->awayTeam?->name }}</span></div></a>@empty<div class="md-panel"><p>No followed-club fixtures in the next seven days.</p></div>@endforelse</div>
    <aside><div class="md-section-title"><p>MAKE YOUR CALL</p><a href="{{ route('predictions.index') }}">Predict</a></div>@forelse($openPredictionSets as $set)<a class="md-predict-card" href="{{ route('predictions.show',$set) }}"><small>Closes {{ $set->prediction_deadline->diffForHumans() }}</small><strong>{{ $set->name }}</strong><span>{{ $set->matches_count }} calls waiting →</span></a>@empty<div class="md-panel"><p>No open challenges.</p></div>@endforelse<a class="md-league-link" href="{{ route('groups.index') }}">Create or join a prediction league →</a></aside></div>
</div></section>
@endauth

<section class="md-home-hero"><div class="md-wrap md-home-hero-grid">
    <div><p class="md-eyebrow">MATCHDAY AFRICA · {{ now()->format('l, j F') }}</p><h1>Your football day<br><em>starts here.</em></h1><p class="md-home-intro">Live scores, upcoming fixtures and the stories connecting African football to the world.</p><div class="md-home-actions"><a class="md-primary" href="{{ route('matches.index') }}">See today’s matches</a><a class="md-secondary" href="{{ route('war.index') }}">Enter Matchday War</a></div></div>
    <div class="md-home-signal" aria-label="Matchday status"><span>{{ $liveMatches->isNotEmpty() ? 'LIVE NOW' : 'NEXT UP' }}</span>@php($signalMatch = $liveMatches->first() ?? $upcomingMatches->first())@if($signalMatch)<small>{{ $signalMatch->league?->name }}</small><strong>{{ $signalMatch->homeTeam?->name }} <i>{{ $liveMatches->isNotEmpty() ? (($signalMatch->home_score ?? '–').'–'.($signalMatch->away_score ?? '–')) : $signalMatch->match_date->format('H:i') }}</i> {{ $signalMatch->awayTeam?->name }}</strong><a href="{{ route('matches.show', $signalMatch) }}">Open match centre →</a>@else<small>THE WATCHTOWER IS READY</small><strong>No fixtures are currently scheduled.</strong><a href="{{ route('matches.index') }}">Browse all matches →</a>@endif</div>
</div></section>

<section class="md-pulse"><div class="md-wrap">
    <div class="md-home-heading"><div><p class="md-eyebrow">MATCHDAY PULSE</p><h2>Now, next and just finished.</h2></div><a href="{{ route('matches.index') }}">Full match centre →</a></div>
    <div class="md-pulse-grid">
        <div class="md-pulse-column md-live-column"><div class="md-pulse-title"><span class="md-pulse-dot"></span><b>LIVE NOW</b><small>Provider verified</small></div>@forelse($liveMatches as $match) @include('partials.home-match-card', ['match' => $match, 'mode' => 'live']) @empty <div class="md-pulse-empty"><strong>No live matches right now.</strong><span>We only show a live badge when the score provider has recently confirmed it.</span></div> @endforelse</div>
        <div class="md-pulse-column"><div class="md-pulse-title"><b>COMING UP</b><small>Next 48 hours</small></div>@forelse($upcomingMatches->take(4) as $match) @include('partials.home-match-card', ['match' => $match, 'mode' => 'upcoming']) @empty <div class="md-pulse-empty"><strong>Nothing scheduled yet.</strong><span>Check the full match centre for more competitions.</span></div> @endforelse</div>
        <div class="md-pulse-column"><div class="md-pulse-title"><b>RESULTS</b><small>Last 24 hours</small></div>@forelse($recentResults->take(4) as $match) @include('partials.home-match-card', ['match' => $match, 'mode' => 'result']) @empty <div class="md-pulse-empty"><strong>No recent results.</strong><span>Finished matches will appear here as they are confirmed.</span></div> @endforelse</div>
    </div>
</div></section>

<section class="md-home-features"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">MORE THAN SCORES</p><h2>Follow the game. Join the conversation.</h2></div></div><div class="md-feature-grid">
    <a class="md-feature-card md-feature-war" href="{{ route('war.index') }}"><span>01 · MATCHDAY WAR</span><h3>Turn the fixture into a battle.</h3><p>Choose your side, rally supporters and experience football rivalry in real time.</p><b>Enter the arena →</b></a>
    <a class="md-feature-card md-feature-predict" href="{{ route('predictions.index') }}"><span>02 · PREDICTIONS</span><h3>Make your call before kickoff.</h3><p>Predict scores, compete with friends and prove how well you know the game.</p><b>Start predicting →</b></a>
</div></div></section>

@if($hasFeaturedBlogs)<section class="md-home-stories"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">FROM THE CONTINENT</p><h2>Football stories worth your time.</h2></div><a href="{{ route('blogs.index') }}">All stories →</a></div><div class="md-story-grid">
    @foreach($featuredBlogs as $blog)<article class="md-story-card {{ $loop->first ? 'md-story-lead' : '' }}"><a href="{{ route('blogs.show', $blog) }}" class="md-story-image">@if($blog->featured_image)<img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}">@else<span>MATCHDAY<br>AFRICA</span>@endif</a><div><small>{{ $blog->formatted_published_date }} · {{ $blog->reading_time }}</small><h3><a href="{{ route('blogs.show', $blog) }}">{{ $blog->title }}</a></h3><p>{{ Str::limit($blog->excerpt, $loop->first ? 190 : 110) }}</p><a href="{{ route('blogs.show', $blog) }}">Read story →</a></div></article>@endforeach
</div></div></section>@endif

@if($featuredLeagues->isNotEmpty())<section class="md-home-leagues"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">EXPLORE</p><h2>Your competitions.</h2></div><a href="{{ route('leagues.index') }}">All competitions →</a></div><div class="md-league-strip">@foreach($featuredLeagues->take(8) as $league)<a href="{{ route('leagues.show', $league) }}">@if($league->logo_url)<img src="{{ $league->logo_url }}" alt="">@else<span>{{ Str::upper(Str::substr($league->name,0,2)) }}</span>@endif<div><strong>{{ $league->name }}</strong><small>{{ $league->country_name }}</small></div></a>@endforeach</div></div></section>@endif

@guest<section class="md-home-join"><div class="md-wrap"><div><p class="md-eyebrow">MAKE IT YOUR MATCHDAY</p><h2>Follow your clubs. Never miss the moment.</h2><p>Create a free account for a personal fixture feed, predictions and supporter experiences.</p></div><div class="md-home-actions"><a class="md-primary" href="{{ route('register') }}">Join free</a><a class="md-secondary" href="{{ route('login') }}">Sign in</a></div></div></section>@endguest
@endsection
