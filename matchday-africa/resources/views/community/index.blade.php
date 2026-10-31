@extends('layouts.public')
@section('meta_title', 'Community: prediction leagues, match rooms and top predictors | Matchday Africa')
@section('meta_description', 'Join free prediction leagues with friends, talk football in live match rooms and see this week\'s top predictors on Matchday Africa.')
@section('content')
<section class="md-phase"><div class="md-wrap">
    <p class="md-eyebrow">THE GRANDSTAND</p>
    <h1>Community</h1>
    <p class="md-lead">Call the scores with your people, talk through every match and climb the weekly table. Free to join.</p>
    <div class="md-card-grid">
        <a class="md-panel" href="{{ auth()->check() ? route('groups.index') : route('register') }}"><small>PREDICTION LEAGUES</small><h2>Start a league with friends</h2><p>Create a private table, send one invite link, and the weekly winner gets the bragging rights.</p></a>
        <a class="md-panel" href="{{ route('matches.index') }}"><small>MATCH ROOMS</small><h2>Talk through the match</h2><p>Every fixture has a live room: react to goals, back your side and call the score before kick-off.</p></a>
        <a class="md-panel" href="{{ route('war.index') }}"><small>MATCHDAY WAR</small><h2>Turn the fixture into a battle</h2><p>Pick your army and challenge a friend on any match.</p></a>
        <a class="md-panel" href="{{ route('leagues.index') }}"><small>COMPETITIONS</small><h2>Tables and fixtures</h2><p>Premier League, AFCON, NPFL and the leagues African fans follow.</p></a>
        @if(\App\Models\RadioBulletin::available())<a class="md-panel" href="{{ route('radio.index') }}"><small>MATCHDAY RADIO</small><h2>Listen in</h2><p>Short football bulletins three times a day.</p></a>@endif
        <a class="md-panel" href="{{ route('reels.index') }}"><small>REELS</small><h2>Every match in 20 seconds</h2><p>Share them to your Status minutes after the final whistle.</p></a>
    </div>

    <div class="md-community-two">
        <section class="md-panel">
            <p class="md-eyebrow">THIS WEEK'S TOP PREDICTORS · FROM {{ strtoupper($weekStart->format('D j M')) }}</p>
            @if($top->isEmpty())
                <p>No points yet this week. <a class="md-gold-link" href="{{ route('matches.index') }}">Make the first call →</a></p>
            @else
                <div class="md-table">@foreach($top as $row)<div><b>{{ $loop->iteration }}</b><span>{{ $row->user->name }}</span><strong>{{ (int) $row->points }} pts</strong></div>@endforeach</div>
            @endif
        </section>
        <section class="md-panel">
            <p class="md-eyebrow">PUBLIC LEAGUES</p>
            @forelse($leagues as $league)
                <p class="md-community-row"><a class="md-gold-link" href="{{ route('groups.invite', $league->code) }}">{{ $league->name }}</a> <small>{{ $league->members_count }} {{ Str::plural('contender', $league->members_count) }}</small></p>
            @empty
                <p>No public leagues yet. Create one and tick "Public league" so anyone can join.</p>
            @endforelse
        </section>
    </div>

    @if($chats->isNotEmpty())
        <p class="md-eyebrow mt-8">LATEST FROM THE MATCH ROOMS</p>
        <div class="md-community-chats">
            @foreach($chats as $chat)
                <a href="{{ route('matches.show', $chat->match) }}#fan-discussion"><small>{{ $chat->match->homeTeam?->name }} v {{ $chat->match->awayTeam?->name }} · {{ $chat->created_at?->diffForHumans() }}</small><span><b>{{ $chat->user?->name ?? 'Fan' }}:</b> {{ Str::limit(strip_tags((string) $chat->message), 120) }}</span></a>
            @endforeach
        </div>
    @endif
</div></section>
<x-social-follow variant="block" context="community" />
@endsection
