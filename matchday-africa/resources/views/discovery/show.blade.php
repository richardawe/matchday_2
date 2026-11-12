@extends('layouts.public')
@section('content')
<section class="md-phase md-phase-compact"><div class="md-wrap md-player-hero">
    @if($photo)
        <figure>
            <img src="{{ $photo['url'] }}" alt="{{ $player->name }}">
            @if($photo['credit'])<figcaption class="text-sm text-gray-500">Photo: <a href="{{ $photo['source'] }}" target="_blank" rel="noopener">{{ $photo['credit'] }}</a></figcaption>@endif
        </figure>
    @endif
    <div>
        <p class="md-eyebrow">@if(\App\Support\AfricanScope::isNational($player)){{ $player->nationality_flag }} @endif{{ strtoupper($label) }}@if($player->position) · {{ strtoupper($player->position) }}@endif</p>
        <h1>{{ $player->name }}</h1>
        <p class="md-lead">
            {{ $club ?: 'Club not known' }}@if($player->nationality). Nationality: {{ $player->nationality }}@endif.
            @if($heritage) {{ ucfirst($label) }}.@endif
        </p>
        @if($heritage && auth()->user()?->is_admin)
            <p class="text-sm">Editors: heritage source <a href="{{ $heritage->source_url }}" target="_blank" rel="noopener">{{ $heritage->source_url }}</a>@if($heritage->source_note) ({{ $heritage->source_note }})@endif.</p>
        @endif
        <div class="md-stat-row">
            @if($player->shirt_number)<span><b>{{ $player->shirt_number }}</b>SHIRT</span>@endif
            @if($player->age || $player->date_of_birth)<span><b>{{ $player->formatted_age }}</b>AGE</span>@endif
            @if($player->preferred_foot)<span><b>{{ $player->preferred_foot }}</b>FOOT</span>@endif
        </div>
    </div>
</div></section>

<section class="md-phase"><div class="md-wrap">
@if($league && $season)
    <p class="md-eyebrow">{{ strtoupper($league->name) }} · THIS SEASON</p>
    <div class="md-stat-row">
        @if(array_key_exists('starts', $season))<span><b>{{ $season['starts'] }}</b>STARTS</span>@endif
        @if(array_key_exists('matchday_squads', $season))<span><b>{{ $season['matchday_squads'] }}</b>MATCHDAY SQUADS</span>@endif
        @if(array_key_exists('goals', $season))<span><b>{{ $season['goals'] }}</b>GOALS</span>@endif
        @if(array_key_exists('assists', $season))<span><b>{{ $season['assists'] }}</b>ASSISTS</span>@endif
        @if(array_key_exists('last_match_minutes', $season))<span><b>{{ $season['last_match_minutes'] }}</b>MIN LAST MATCH</span>@endif
    </div>
    <p class="text-sm text-gray-500">From the matches in our data. Numbers we don't have are left out.</p>
@endif

@if($fixtures['upcoming']->isNotEmpty() || $fixtures['recent']->isNotEmpty())
    <p class="md-eyebrow" style="margin-top:2rem">{{ strtoupper($club ?? 'CLUB') }} FIXTURES</p>
    @if($fixtures['upcoming']->isNotEmpty())
        <h2>Next up</h2>
        <ul class="md-fixture-list">@foreach($fixtures['upcoming'] as $m)
            <li><a href="{{ route('matches.show', $m) }}">{{ $m->homeTeam?->name }} v {{ $m->awayTeam?->name }}</a> <small>{{ \Carbon\Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone('Europe/London')->format('D j M, H:i T') }} · {{ $m->league?->name }}</small></li>
        @endforeach</ul>
    @endif
    @if($fixtures['recent']->isNotEmpty())
        <h2>Recent results</h2>
        <ul class="md-fixture-list">@foreach($fixtures['recent'] as $m)
            <li><a href="{{ route('matches.show', $m) }}">{{ $m->homeTeam?->name }} {{ $m->home_score }}-{{ $m->away_score }} {{ $m->awayTeam?->name }}</a> <small>{{ \Carbon\Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone('Europe/London')->format('D j M') }} · {{ $m->league?->name }}</small></li>
        @endforeach</ul>
    @endif
@endif

    @include('partials.player-cta', ['cta' => $cta])
    <x-share-bar :url="route('discovery.show', $player)" :text="$player->name.' on Matchday Africa.'" campaign="player" />
    @if(($slideshows ?? collect())->isNotEmpty())@include('partials.slideshow-strip', ['slideshows' => $slideshows, 'heading' => 'SLIDESHOWS FEATURING '.mb_strtoupper($player->name)])@endif
</div></section>

@if($history->isNotEmpty())<section class="md-phase"><div class="md-wrap"><p class="md-eyebrow">STATSBOMB OPEN DATA</p><h2>AFCON match history.</h2><div class="overflow-x-auto bg-white rounded-xl border border-gray-200 mt-6"><table class="min-w-full text-sm"><thead><tr>@foreach(['Match','Min','G','A','Shots','Passes','Key passes','Tackles','Interceptions'] as $heading)<th class="p-3 text-left">{{ $heading }}</th>@endforeach</tr></thead><tbody>@foreach($history as $game)<tr class="border-t"><td class="p-3"><strong>{{ $game->team }} vs {{ $game->opponent }}</strong><small class="block text-gray-500">{{ $game->match_date->format('j M Y') }}</small></td><td class="p-3">{{ $game->minutes ?: '—' }}</td><td class="p-3">{{ $game->goals }}</td><td class="p-3">{{ $game->assists }}</td><td class="p-3">{{ $game->shots }}</td><td class="p-3">{{ $game->completed_passes }}/{{ $game->passes }}</td><td class="p-3">{{ $game->key_passes }}</td><td class="p-3">{{ $game->tackles }}</td><td class="p-3">{{ $game->interceptions }}</td></tr>@endforeach</tbody></table></div><p class="text-sm text-gray-500 mt-4">Data source: <a class="underline" href="https://github.com/statsbomb/open-data" target="_blank" rel="noopener noreferrer">StatsBomb Open Data</a>.</p></div></section>@endif
@endsection
