{{-- Beat the Griot strip on both homes (GRIOT_CHALLENGE): the round's first call and the dare. --}}
@if(\App\Services\Griot\GriotChallengeService::ready() && ($griotRound = app(\App\Services\Griot\GriotChallengeService::class)->open()) && ($griotFirst = $griotRound->picks->first()))
@php($club = fn ($t) => \App\Support\ClubName::short($t))
<section class="md-griot-strip" aria-labelledby="griot-strip-title"><div class="md-wrap">
    <a class="md-griot-strip-link" href="{{ route('griot.challenge') }}">
        <small class="md-eyebrow">BEAT THE GRIOT · {{ mb_strtoupper($griotRound->datesLabel()) }}</small>
        <strong id="griot-strip-title">Griot says {{ $club($griotFirst->match->homeTeam) }} {{ $griotFirst->call() }} {{ $club($griotFirst->match->awayTeam) }}. You?</strong>
        <span>3 matches · {{ config('griot_challenge.prize') }} for {{ (int) config('griot_challenge.winners') }} fans who beat it</span>
        <b class="md-primary">Take on the Griot</b>
    </a>
</div></section>
@endif
