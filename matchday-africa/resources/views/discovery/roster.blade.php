@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap">
    <p class="md-eyebrow">THE WEEKLY ROSTER · {{ strtoupper($league->name) }}</p>
    <h1>African players in the {{ $league->name }}, club by club</h1>
    <p class="md-lead">African internationals and players of confirmed African descent at {{ $league->name }} clubs, from our match data. As of {{ $asOf->format('j F Y, H:i T') }}.</p>
    @if($leagues->count() > 1)
        <nav class="md-roster-tabs" aria-label="Leagues">
            @foreach($leagues as $l)
                @php($href = \App\Services\Slideshow\SlideshowFacts::isPremierLeague($l) ? route('discovery.roster') : route('discovery.league', $l->short_code ?: $l->espn_slug))
                <a href="{{ $href }}" @if($l->id === $league->id) aria-current="page" class="md-primary" @else class="md-secondary" @endif>{{ $l->name }}</a>
            @endforeach
        </nav>
    @endif

    @if(empty($roster['clubs']))
        <div class="md-panel"><h2>No players listed yet</h2><p>Nobody in our data for this league is an African international or has confirmed African heritage.</p></div>
    @else
        <p>{{ $roster['count'] }} {{ \Illuminate\Support\Str::plural('player', $roster['count']) }} at {{ count($roster['clubs']) }} {{ \Illuminate\Support\Str::plural('club', count($roster['clubs'])) }}.</p>
        @foreach($roster['clubs'] as $club)
            <section class="md-roster-club" aria-labelledby="club-{{ $club['team_id'] }}">
                <h2 id="club-{{ $club['team_id'] }}">{{ $club['club'] }}</h2>
                <table class="md-roster-table">
                    <thead><tr><th>Player</th><th>Country</th><th>Position</th><th>League this season</th></tr></thead>
                    <tbody>
                    @foreach($club['players'] as $p)
                        @php($s = $p['season'])
                        <tr>
                            <td><a href="{{ route('discovery.show', $p['id']) }}">{{ $p['name'] }}</a></td>
                            <td>{{ $p['flag'] }} {{ $p['label'] }}</td>
                            <td>{{ $p['position'] ?: '' }}</td>
                            <td>{{ collect([
                                array_key_exists('starts', $s) ? $s['starts'].' '.\Illuminate\Support\Str::plural('start', $s['starts']) : null,
                                array_key_exists('goals', $s) ? $s['goals'].' '.\Illuminate\Support\Str::plural('goal', $s['goals']) : null,
                                array_key_exists('assists', $s) ? $s['assists'].' '.\Illuminate\Support\Str::plural('assist', $s['assists']) : null,
                                array_key_exists('last_match_minutes', $s) ? $s['last_match_minutes'].' min last match' : null,
                            ])->filter()->join(' · ') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach
        <p class="text-sm text-gray-500">Numbers come from the matches in our data; a number we don't have is left blank rather than shown as zero. Heritage is listed only when an editor has confirmed it with a public source.</p>
    @endif

    <x-share-bar :url="$pageUrl" :text="'African players in the '.$league->name.' this week, club by club.'" campaign="roster" />
    @include('partials.player-cta', ['cta' => $cta])
</div></section>
@endsection
