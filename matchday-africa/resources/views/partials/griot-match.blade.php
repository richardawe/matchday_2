{{-- On a match page: the Griot's call when this match is in the open Beat the Griot round. --}}
@if(\App\Services\Griot\GriotChallengeService::ready() && ($griotPick = \App\Models\GriotChallengeMatch::where('match_id', $match->id)->whereHas('challenge', fn ($q) => $q->where('status', 'open'))->first()))
<a class="md-griot-badge" href="{{ route('griot.challenge') }}"><span><small>GRIOT</small><b>{{ $griotPick->call() }}</b></span><p>This match is in Beat the Griot. Your call here counts: beat the Griot across its three matches to be in the draw for {{ config('griot_challenge.prize') }}.</p></a>
@endif
