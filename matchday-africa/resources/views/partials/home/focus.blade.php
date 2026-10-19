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
