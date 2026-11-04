@php($mood = \App\Support\ArmyMoods::image($team, 'warrior'))
@php($club = trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $team?->name)) ?: 'TBC')
<div class="md-griot-team">
    @if($mood)<img src="{{ $mood['url'] }}" alt="{{ $mood['alt'] }}" width="72" height="72" loading="lazy" @class(['is-logo' => $mood['logo']])>@else<span class="md-griot-mono" aria-hidden="true">{{ mb_strtoupper(mb_substr($club, 0, 3)) }}</span>@endif
    <strong>{{ $club }}</strong>
    @if($army = \App\Support\Armies::for($team))<small>{{ $army['army'] }}</small>@endif
</div>
