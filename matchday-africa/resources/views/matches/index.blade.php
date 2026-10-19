@extends('layouts.public')

@section('content')
@php
    $keep = fn (array $changes) => array_filter(array_merge(request()->only(['filter', 'date', 'region', 'league_id', 'q']), $changes), fn ($v) => $v !== null && $v !== '');
    $dayTabs = ['yesterday' => 'Yesterday', 'today' => 'Today', 'tomorrow' => 'Tomorrow'];
    $heading = $filter === 'live' ? 'Live now' : ($day->isToday() ? 'Today' : ($day->isYesterday() ? 'Yesterday' : ($day->isTomorrow() ? 'Tomorrow' : $day->format('l j F'))));
@endphp
<section class="md-centre"><div class="md-wrap">
    <header class="md-centre-head">
        <div><p class="md-eyebrow">MATCH CENTRE</p><h1>{{ $heading }}</h1><p>{{ $filter === 'live' ? 'Every match in play right now.' : $day->format('l j F Y') }} · {{ $matchTotal }} {{ Str::plural('match', $matchTotal) }}</p></div>
    </header>

    <nav class="md-centre-days" aria-label="Choose a day">
        <a href="{{ route('matches.index', ['filter' => 'live']) }}" class="{{ $filter === 'live' ? 'is-active' : '' }} md-centre-live"><span class="md-pulse-dot"></span>Live <b>{{ $liveCount }}</b></a>
        @foreach($dayTabs as $key => $label)
            <a href="{{ route('matches.index', ['filter' => $key]) }}" class="{{ $filter === $key ? 'is-active' : '' }}">{{ $label }}</a>
        @endforeach
        <form method="GET" action="{{ route('matches.index') }}" class="md-centre-date"><input type="hidden" name="filter" value="date"><label><span class="sr-only">Pick a date</span><input type="date" name="date" value="{{ $filter === 'date' ? $day->toDateString() : '' }}" onchange="this.form.submit()"></label></form>
    </nav>

    <div class="md-centre-filters">
        <div class="md-centre-regions" role="group" aria-label="Region">
            <a href="{{ route('matches.index', $keep(['region' => null, 'league_id' => null])) }}" class="{{ !$region ? 'is-active' : '' }}">All <b>{{ $regionCounts->sum() }}</b></a>
            @foreach($regions as $key => $label)
                @if($regionCounts->get($key))
                    <a href="{{ route('matches.index', $keep(['region' => $key, 'league_id' => null])) }}" class="{{ $region === $key ? 'is-active' : '' }}">{{ $label }} <b>{{ $regionCounts->get($key) }}</b></a>
                @endif
            @endforeach
        </div>
        <form method="GET" action="{{ route('matches.index') }}" class="md-centre-search">
            @foreach(request()->only(['filter', 'date', 'region']) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
            <select name="league_id" onchange="this.form.submit()" aria-label="Competition">
                <option value="">All competitions</option>
                @foreach($leaguesToday->groupBy(fn ($league) => $league->region) as $regionKey => $leagues)
                    <optgroup label="{{ $regions[$regionKey] ?? 'More football' }}">
                        @foreach($leagues as $league)<option value="{{ $league->id }}" @selected(request('league_id') == $league->id)>{{ $league->name }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
            <input type="search" name="q" value="{{ $search }}" placeholder="Search a team" aria-label="Search a team">
            <button type="submit">Search</button>
        </form>
    </div>

    @forelse($groups as $group)
        @php
            $league = $group->first()->league;
        @endphp
        <section class="md-centre-group">
            <header><div>@if($league?->logo_url)<img src="{{ $league->logo_url }}" alt="">@else<span>{{ Str::upper(Str::substr($league?->name ?? 'F', 0, 2)) }}</span>@endif<h2>{{ $league?->name ?? 'Football' }}</h2><small>{{ $league?->country_name }}</small></div>@if($league)<a href="{{ route('leagues.show', $league) }}">Table &amp; fixtures →</a>@endif</header>
            @foreach($group as $match)
                @php
                    $live = in_array($match->status, \App\Models\FootballMatch::LIVE_STATUSES, true);
                    $clock = match (true) {
                        $live => $match->live_label,
                        $match->isFinished() => 'FT',
                        in_array($match->status, ['POSTPONED', 'CANCELLED'], true) => $match->status_display,
                        default => $match->match_date->format('H:i'),
                    };
                @endphp
                <div class="md-centre-row {{ $live ? 'is-live' : '' }}">
                    <a href="{{ route('matches.show', $match) }}">
                        <time>{{ $clock }}</time>
                        <span class="md-centre-team">{{ $match->homeTeam?->display_name ?? 'Home' }}</span>
                        <b>{{ $match->home_score !== null ? $match->home_score.' – '.$match->away_score : 'v' }}</b>
                        <span class="md-centre-team md-centre-away">{{ $match->awayTeam?->display_name ?? 'Away' }}</span>
                    </a>
                    <span class="md-centre-extra">@if($league?->short_code === 'PL' && !$match->isFinished())<a class="md-centre-odds" href="{{ route('odds.index') }}#{{ $match->id }}">Odds</a>@endif</span>
                </div>
            @endforeach
        </section>
    @empty
        <div class="md-centre-empty">
            <strong>{{ $filter === 'live' ? 'No matches are live right now.' : 'No matches found for this day.' }}</strong>
            @if($region || request('league_id') || $search)<a href="{{ route('matches.index', ['filter' => $filter, 'date' => request('date')]) }}">Clear filters</a>@else<a href="{{ route('matches.index', ['filter' => 'tomorrow']) }}">See tomorrow's fixtures →</a>@endif
        </div>
    @endforelse
</div></section>
@endsection
