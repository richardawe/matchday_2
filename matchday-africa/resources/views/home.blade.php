@extends('layouts.public')

@section('content')
<x-sponsor-slot slot="home" />

@if($premierLeagueMatches->isNotEmpty())
<section class="md-war-takeover"><div class="md-wrap"><header class="md-war-heading"><div><p>PREMIER LEAGUE · TODAY’S CAMPAIGN</p><h1>Choose your banner.<br><em>Enter the field.</em></h1></div><a href="{{ route('war.index') }}">ENTER MATCHDAY WAR →</a></header><div class="md-war-fixtures">@foreach($premierLeagueMatches as $fixture)<article class="md-war-clash"><small>{{ $fixture->match_date->format('H:i') }} · {{ Str::upper($fixture->status_display) }}</small><div class="md-war-armies"><div>@if(data_get($fixture->war_home,'image'))<img src="{{ data_get($fixture->war_home,'image') }}" alt="{{ $fixture->homeTeam?->display_name }} warrior">@endif<span>HOME BANNER</span><strong>{{ $fixture->homeTeam?->display_name }}</strong><em>{{ data_get($fixture->war_home,'faction') }}</em></div><b><i>{{ $fixture->home_score !== null ? $fixture->home_score.' — '.$fixture->away_score : 'VS' }}</i><span>THE FIELD AWAITS</span></b><div>@if(data_get($fixture->war_away,'image'))<img src="{{ data_get($fixture->war_away,'image') }}" alt="{{ $fixture->awayTeam?->display_name }} warrior">@endif<span>AWAY BANNER</span><strong>{{ $fixture->awayTeam?->display_name }}</strong><em>{{ data_get($fixture->war_away,'faction') }}</em></div></div><footer><a href="{{ route('matches.show',$fixture) }}">VIEW MATCH CENTRE</a><a href="{{ route('war.match',$fixture) }}#game">KICK OFF THE BATTLE</a></footer></article>@endforeach</div></div></section>
@elseif($hasFeaturedBlogs)
@php($hasFeaturedBlogs = false)
<section class="md-home-stories md-home-stories-first"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">LATEST NEWS</p><h1>Football stories worth your time.</h1></div><a href="{{ route('blogs.index') }}">All stories →</a></div><div class="md-story-grid">@foreach($featuredBlogs as $blog)<article class="md-story-card {{ $loop->first ? 'md-story-lead' : '' }}"><a href="{{ route('blogs.show', $blog) }}" class="md-story-image">@if($blog->featured_image)<img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}">@else<span>MATCHDAY<br>AFRICA</span>@endif</a><div><small>{{ $blog->formatted_published_date }} · {{ $blog->reading_time }}</small><h3><a href="{{ route('blogs.show', $blog) }}">{{ $blog->title }}</a></h3><p>{{ Str::limit($blog->excerpt, $loop->first ? 190 : 110) }}</p><a href="{{ route('blogs.show', $blog) }}">Read story →</a></div></article>@endforeach</div></div></section>
@endif

@auth
<section class="md-command"><div class="md-wrap">
    <div class="md-command-head"><div><p class="md-eyebrow">YOUR MATCHDAY</p><h1>Welcome back, {{ Str::before(auth()->user()->name, ' ') }}.</h1><p>Fixtures, predictions and stories from the clubs you follow.</p></div><a class="md-secondary" href="{{ route('onboarding') }}">{{ $followedTeams->isEmpty()?'Choose clubs':'Edit clubs' }}</a></div>
    @if($followedTeams->isEmpty())<a href="{{ route('onboarding') }}" class="md-onboard"><strong>Make Matchday yours.</strong><span>Choose the teams you follow →</span></a>@else<div class="md-following">@foreach($followedTeams as $team)<span>@if($team->logo_url)<img src="{{ $team->logo_url }}" alt="">@endif{{ $team->display_name }}</span>@endforeach</div>@endif
    <div class="md-command-grid"><div class="md-command-main"><div class="md-section-title"><p>YOUR NEXT MATCHES</p><a href="{{ route('matches.index') }}">All matches</a></div>
    @forelse($personalMatches as $fixture)<a class="md-fixture" href="{{ route('matches.show',$fixture) }}"><small>{{ $fixture->league?->name }} · {{ $fixture->match_date->isToday()?'TODAY':strtoupper($fixture->match_date->format('D j M')) }}</small><div><span>{{ $fixture->homeTeam?->name }}</span><b>{{ $fixture->home_score!==null?$fixture->home_score:$fixture->match_date->format('H:i') }} — {{ $fixture->away_score!==null?$fixture->away_score:'' }}</b><span>{{ $fixture->awayTeam?->name }}</span></div></a>@empty<div class="md-panel"><p>No followed-club fixtures in the next seven days.</p></div>@endforelse</div>
    <aside><div class="md-section-title"><p>MAKE YOUR CALL</p><a href="{{ route('predictions.index') }}">Predict</a></div>@forelse($openPredictionSets as $set)<a class="md-predict-card" href="{{ route('predictions.show',$set) }}"><small>Closes {{ $set->prediction_deadline->diffForHumans() }}</small><strong>{{ $set->name }}</strong><span>{{ $set->matches_count }} calls waiting →</span></a>@empty<div class="md-panel"><p>No open challenges.</p></div>@endforelse<a class="md-league-link" href="{{ route('groups.index') }}">Create or join a prediction league →</a></aside></div>
</div></section>
@endauth

<section class="md-focus-hero"><div class="md-wrap"><div class="md-focus-lead"><div><p class="md-eyebrow">AFRICAN PLAYERS IN FOCUS</p><h1>African talent<br><em>playing today.</em></h1><p>See who is involved, the match they are playing in, and every performance number available from API-Football.</p></div><a href="{{ route('discovery.index') }}">Explore all African players →</a></div>
@if($africanPlayersInFocus->isEmpty())
<div class="md-focus-empty"><h2>No African players are linked to today’s fixtures yet.</h2><p>Player cards will appear after today’s squads and API enrichment finish syncing.</p></div>
@else
@foreach($africanPlayersInFocus->take(8) as $player)
@php($fixture=$player->focus_match)
@php($stats=$player->focus_stats??[])
@php($game=data_get($stats,'games',[]))
<article class="md-focus-player"><a class="md-focus-person" href="{{ route('discovery.show',$player) }}">@if($player->photo_url)<img src="{{ $player->photo_url }}" alt="{{ $player->name }}">@else<span>{{ Str::upper(Str::substr($player->name,0,1)) }}</span>@endif<div><small>{{ $player->nationality_flag }} {{ $player->nationality }}</small><h2>{{ $player->name }}</h2><p>{{ $player->position }} · {{ $player->team?->display_name }}</p></div></a>@if($fixture)<a class="md-focus-fixture" href="{{ route('matches.show',$fixture) }}"><small>{{ $fixture->league?->name }} · {{ $fixture->match_date->format('H:i') }}</small><strong>{{ $fixture->homeTeam?->display_name }} <i>{{ $fixture->home_score!==null?$fixture->home_score.'–'.$fixture->away_score:'vs' }}</i> {{ $fixture->awayTeam?->display_name }}</strong><span>Open match centre →</span></a>@endif
<div class="md-focus-summary"><span><b>{{ data_get($game,'minutes','—') }}</b> Minutes</span><span><b>{{ data_get($game,'rating','—') }}</b> Rating</span><span><b>{{ data_get($stats,'goals.total','—') }}</b> Goals</span><span><b>{{ data_get($stats,'goals.assists','—') }}</b> Assists</span><span><b>{{ data_get($stats,'shots.total','—') }}</b> Shots</span><span><b>{{ data_get($stats,'passes.total','—') }}</b> Passes</span></div>
@if($stats)<details class="md-focus-all"><summary>All available match stats</summary><div>@foreach($player->focus_stat_rows as $row)<span><small>{{ $row['label'] }}</small><b>{{ $row['value'] }}</b></span>@endforeach</div></details>@else<p class="md-focus-awaiting">Detailed stats will appear when API-Football publishes the lineup or match data.</p>@endif</article>
@endforeach
@endif
</div></section>

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

@if($featuredLeagues->isNotEmpty())<section class="md-home-leagues"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">EXPLORE</p><h2>Your competitions.</h2></div><a href="{{ route('leagues.index') }}">All competitions →</a></div><div class="md-league-strip">@foreach($featuredLeagues->take(8) as $league)<a href="{{ route('leagues.show', $league) }}">@if($league->logo_url)<img src="{{ $league->logo_url }}" alt="">@else<span>{{ Str::upper(Str::substr($league->name,0,2)) }}</span>@endif<div><strong>{{ $league->name }}</strong><small>{{ $league->country_name }}</small></div></a>@endforeach</div></div></section>@endif

@guest<section class="md-home-join"><div class="md-wrap"><div><p class="md-eyebrow">MAKE IT YOUR MATCHDAY</p><h2>Follow your clubs. Never miss the moment.</h2><p>Create a free account for a personal fixture feed, predictions and supporter experiences.</p></div><div class="md-home-actions"><a class="md-primary" href="{{ route('register') }}">Join free</a><a class="md-secondary" href="{{ route('login') }}">Sign in</a></div></div></section>@endguest

<aside class="md-ngr-ad" aria-label="Sponsored by NGR"><div class="md-wrap"><div><small>SPONSORED</small><strong>NGR<span>.</span></strong></div><p>Discover more from NGR.</p><a href="https://ngr.ltd/" target="_blank" rel="sponsored noopener">VISIT NGR.LTD →</a></div></aside>
@endsection
