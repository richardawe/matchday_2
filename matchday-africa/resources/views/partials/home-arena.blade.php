@php
    $match = $arena['match'];
    $home = $arena['home'];
    $away = $arena['away'];
    $club = fn ($team) => trim(preg_replace('/\s+FC$/i', '', (string) $team?->name));
@endphp
<section id="arena" class="md-arena" data-state="{{ $arena['state'] }}" data-kickoff="{{ $arena['kickoff'] }}" data-match="{{ $match->id }}"
    data-home-colour="{{ $home['glow'] }}" data-away-colour="{{ $away['glow'] }}"
    data-home-art="{{ $home['cutout'] }}" data-away-art="{{ $away['cutout'] }}"
    style="--home: {{ $home['colours'][0] }}; --away: {{ $away['colours'][0] }}; --home-glow: {{ $home['glow'] }}; --away-glow: {{ $away['glow'] }}">
    <div class="md-arena-stage" aria-hidden="true">
        <div class="md-arena-glow md-arena-glow-home"></div>
        <div class="md-arena-glow md-arena-glow-away"></div>
        <div class="md-arena-floor"></div>
        @if($home['cutout'])<img class="md-arena-army md-arena-home" src="{{ $home['cutout'] }}" alt="" fetchpriority="high">@endif
        @if($away['cutout'])<img class="md-arena-army md-arena-away" src="{{ $away['cutout'] }}" alt="" fetchpriority="high">@endif
        <canvas class="md-arena-dust"></canvas>
    </div>
    <div class="md-arena-hud md-wrap">
        <p class="md-arena-label">@if($arena['state'] === 'live')<span class="md-pulse-dot"></span>@endif<span>PREMIER LEAGUE · <b data-arena-label>{{ Str::upper($arena['label']) }}</b></span></p>
        <div class="md-arena-drums" aria-label="Score">
            @if($arena['state'] === 'upcoming')
                <span class="md-arena-countdown" data-arena-countdown>{{ $match->match_date->format('D H:i') }}</span>
            @else
                <span class="md-arena-drum" data-arena-home>{{ $match->home_score ?? 0 }}</span><i>:</i><span class="md-arena-drum" data-arena-away>{{ $match->away_score ?? 0 }}</span>
            @endif
        </div>
        <h1>{{ $home['army'] }} <em>v</em> {{ $away['army'] }}</h1>
        <p class="md-arena-clubs">{{ $club($match->homeTeam) }} v {{ $club($match->awayTeam) }} · told by the Griot</p>
        <div class="md-arena-actions">
            <a class="md-primary" href="{{ route('matches.show', $match) }}">{{ $arena['state'] === 'live' ? 'Follow it live' : ($arena['state'] === 'upcoming' ? 'Enter the match room' : 'Read the story') }}</a>
            @if($arena['reel'])<a class="md-secondary" href="{{ route('matches.show', $match) }}#match-story">Watch the 20-second reel</a>@endif
            @if($arena['state'] === 'upcoming')<a class="md-secondary" href="{{ route('matches.show', $match) }}#prediction-arena">Predict the score</a>@endif
        </div>
    </div>
    <div class="md-arena-kente"></div>
</section>
