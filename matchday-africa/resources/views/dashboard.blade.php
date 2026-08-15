@extends('layouts.app')

@section('content')
@php
    $firstName = explode(' ', trim(auth()->user()->name))[0];
    $nextMatch = $recentMatches->first(fn ($match) => $match->match_date->isFuture()) ?? $recentMatches->first();
@endphp

<section class="md-dash-hero">
    <div class="md-wrap md-dash-hero-grid">
        <div>
            <p class="md-eyebrow">YOUR MATCHDAY · {{ now()->format('D, d M') }}</p>
            <h1>Welcome back,<br><em>{{ $firstName }}.</em></h1>
            <p>Your football world in one place — fixtures to follow, calls to make, and your form across the season.</p>
            <div class="md-home-actions">
                <a class="md-primary" href="{{ route('matches.index') }}">Explore fixtures</a>
                <a class="md-secondary" href="{{ route('predictions.index') }}">Make a prediction</a>
            </div>
        </div>

        @if($nextMatch)
            <a class="md-dash-next" href="{{ route('matches.show', $nextMatch) }}">
                <span>NEXT ON YOUR RADAR</span>
                <small>{{ $nextMatch->league?->name ?? 'Football' }} · {{ $nextMatch->match_date->format('D, H:i') }}</small>
                <div>
                    <strong>{{ $nextMatch->homeTeam?->name ?? 'TBC' }}</strong>
                    <i>V</i>
                    <strong>{{ $nextMatch->awayTeam?->name ?? 'TBC' }}</strong>
                </div>
                <b>Enter match room →</b>
            </a>
        @else
            <div class="md-dash-next md-dash-next-empty">
                <span>MATCH CENTRE</span>
                <strong>The next fixtures are being prepared.</strong>
                <a href="{{ route('matches.index') }}">Browse all matches →</a>
            </div>
        @endif
    </div>
</section>

<section class="md-dash-scorecard">
    <div class="md-wrap">
        <div><span>SEASON POINTS</span><strong>{{ number_format($userStats['total_points'] ?? 0) }}</strong><small>Your running total</small></div>
        <div><span>GLOBAL RANK</span><strong>{{ ($userStats['rank'] ?? 0) > 0 ? '#'.number_format($userStats['rank']) : '—' }}</strong><small>All-time table</small></div>
        <div><span>ACCURACY</span><strong>{{ number_format($userStats['accuracy_percentage'] ?? 0, 0) }}%</strong><small>{{ $userStats['correct_predictions'] ?? 0 }} correct calls</small></div>
        <div><span>CALLS MADE</span><strong>{{ number_format($userStats['total_predictions'] ?? 0) }}</strong><small>Across all challenges</small></div>
    </div>
</section>

<section class="md-dash-body">
    <div class="md-wrap md-dash-layout">
        <div>
            <header class="md-dash-heading">
                <div><p class="md-eyebrow">THE FIXTURE DESK</p><h2>Matches to watch</h2></div>
                <a href="{{ route('matches.index') }}">Full fixture list →</a>
            </header>
            <div class="md-dash-fixtures">
                @forelse($recentMatches as $match)
                    <a href="{{ route('matches.show', $match) }}" class="md-dash-fixture">
                        <div>
                            <small>{{ $match->league?->name ?? 'Football' }}</small>
                            <time>{{ $match->match_date->isToday() ? 'TODAY · '.$match->match_date->format('H:i') : $match->match_date->format('D d M · H:i') }}</time>
                        </div>
                        <p><strong>{{ $match->homeTeam?->name ?? 'TBC' }}</strong><i>vs</i><strong>{{ $match->awayTeam?->name ?? 'TBC' }}</strong></p>
                        <span>Match room →</span>
                    </a>
                @empty
                    <div class="md-dash-fixture-empty"><strong>No matches on the board yet.</strong><p>Check the full fixture centre for the latest schedule.</p></div>
                @endforelse
            </div>
        </div>

        <aside class="md-dash-rail">
            <p class="md-eyebrow">QUICK PLAY</p>
            <h2>What will you do next?</h2>
            <a href="{{ route('predictions.index') }}"><span>01</span><div><strong>Call the score</strong><small>Make your picks before kickoff.</small></div><b>→</b></a>
            <a href="{{ route('predictions.leaderboard') }}"><span>02</span><div><strong>Check your rank</strong><small>See who is reading the game best.</small></div><b>→</b></a>
            <a href="{{ route('groups.index') }}"><span>03</span><div><strong>Join your people</strong><small>Compete in a private prediction league.</small></div><b>→</b></a>
            <a href="{{ url('/war') }}"><span>04</span><div><strong>Enter The War</strong><small>Rank kits, play and claim your warrior.</small></div><b>→</b></a>
        </aside>
    </div>
</section>

<section class="md-dash-cta">
    <div class="md-wrap">
        <div><p class="md-eyebrow">BUILD YOUR TABLE</p><h2>Football is better with rivals.</h2><p>Create a prediction league, invite your friends and settle the debate every matchweek.</p></div>
        <a class="md-primary" href="{{ route('groups.index') }}">Start a league</a>
    </div>
</section>
@endsection
