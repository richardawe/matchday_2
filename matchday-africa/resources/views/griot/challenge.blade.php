@extends('layouts.public')
@section('meta_title', 'Beat the Griot: three matches, '.config('griot_challenge.prize').' to win | Matchday Africa')
@section('meta_description', 'Every Premier League round the Griot calls three scores. Score more points than it and you could win '.config('griot_challenge.prize').'. Free to play.')
@if($open)@section('meta_image', \App\Services\Griot\ChallengeCardRenderer::cardUrl($open, 'calls'))@endif
@php($prize = config('griot_challenge.prize'))
@php($winnersCount = (int) config('griot_challenge.winners'))
@php($accounts = collect(config('social.accounts'))->only(['x', 'instagram']))
@section('content')
<section class="md-phase md-griot"><div class="md-wrap md-griot-wrap">
    <header class="md-griot-head">
        <p class="md-eyebrow">BEAT THE GRIOT{{ $open ? ' · '.mb_strtoupper($open->datesLabel()) : '' }}</p>
        <h1>Beat <span>the Griot</span></h1>
        <div class="md-griot-says">
            <span class="md-griot-drum" aria-hidden="true"><svg width="34" height="40" viewBox="0 0 38 44"><path d="M3 3h32L23 22l12 19H3l12-19z" fill="currentColor" fill-opacity=".15" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/><path d="M9 6l10 16-10 16M29 6L19 22l10 16" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></span>
            <p>@if($open){{ ['en' => 'I have made my three calls. Score more points than me and '.$prize.' could be yours.', 'pcm' => 'I don call my three. If you sabi ball pass me, '.$prize.' fit be your own.', 'sw' => 'Nimeshatabiri mechi tatu. Nishinde kwa pointi na unaweza kushinda '.$prize.'.', 'za' => 'I\'ve made my three calls. Beat my points and '.$prize.' could be yours, neh.'][$voice] }}@else{{ 'My next three calls come when the next Premier League round is set.' }}@endif
            @if($last)<small>Last round: the Griot scored {{ (int) $last->griot_points }} {{ Str::plural('point', (int) $last->griot_points) }}, {{ number_format($last->beaters) }} of {{ number_format($last->players) }} {{ Str::plural('fan', $last->players) }} beat it.</small>@endif</p>
        </div>
        <nav class="md-griot-voices" aria-label="Griot's language">@foreach(\App\Services\Griot::VOICES as $code => $label)<a href="{{ route('griot.challenge', ['voice' => $code]) }}" @if($voice === $code) aria-current="true" @endif>{{ $label }}</a>@endforeach</nav>
    </header>

    @if(session()->has('griot_saved'))
        <p class="md-griot-toast" role="status">{{ session('griot_saved') ? 'Your calls are locked in. You can change a call until its kick-off.' : 'Those matches have kicked off, so no calls were saved.' }}</p>
    @endif

    @if($myWin)
        <section class="md-griot-won" aria-labelledby="griot-won">
            <h2 id="griot-won">You won {{ $prize }}!</h2>
            @if($myWin->credited_at)
                <p>Credited to {{ $myWin->network }} on {{ $myWin->credited_at->format('D j M') }}. Enjoy the data.</p>
            @else
                <p>Send a message to @foreach($accounts as $a)<a href="{{ $a['url'] }}" rel="noopener" target="_blank">{{ $a['handle'] }} on {{ $a['name'] }}</a>@if(!$loop->last) or @endif @endforeach with:</p>
                <ol><li>your code <b class="md-griot-code">{{ $myWin->code }}</b></li><li>your phone number ({{ config('griot_challenge.country') }})</li><li>your network: {{ implode(', ', config('griot_challenge.networks')) }}</li></ol>
                <p><small>We credit the data by hand, usually within a day. We never ask for a PIN or a password.</small></p>
            @endif
        </section>
    @endif

    @if($open)
        <form method="POST" action="{{ route('griot.calls') }}" class="md-griot-form">@csrf
            @foreach($open->picks as $pick)
                @php($m = $pick->match)
                @php($callable = $service->callable($m))
                @php($mine = $calls[$m->id] ?? null)
                <article class="md-griot-match">
                    <div class="md-griot-top"><span>{{ $m->kickoffLabel('D j M · H:i') }}</span><span class="md-griot-closes">@if($callable)Closes {{ $service->kickoff($m)->diffForHumans() }}@else Closed @endif</span></div>
                    <div class="md-griot-teams">
                        @include('griot._team', ['team' => $m->homeTeam])
                        <b class="md-griot-vs">VS</b>
                        @include('griot._team', ['team' => $m->awayTeam])
                    </div>
                    <div class="md-griot-pick"><div><small>GRIOT</small><b>{{ $pick->call() }}</b></div><p>{{ $lines->line($pick, $voice) }}</p></div>
                    <div class="md-griot-yours">
                        <span class="md-griot-label">YOU</span>
                        @if($callable)
                            <label><span class="sr-only">{{ $m->homeTeam?->name }} goals</span><input type="number" inputmode="numeric" min="0" max="20" required name="calls[{{ $m->id }}][home]" value="{{ $mine[0] ?? '' }}" placeholder="–"></label>
                            <b>:</b>
                            <label><span class="sr-only">{{ $m->awayTeam?->name }} goals</span><input type="number" inputmode="numeric" min="0" max="20" required name="calls[{{ $m->id }}][away]" value="{{ $mine[1] ?? '' }}" placeholder="–"></label>
                        @else
                            <b class="md-griot-locked">{{ $mine ? $mine[0].'–'.$mine[1] : 'No call' }}</b>
                        @endif
                    </div>
                </article>
            @endforeach
            <div class="md-griot-prize"><b>{{ preg_replace('/\s+of data$/i', '', $prize) }}</b><p>Score more points than the Griot across all three and you're in the draw: {{ $winnersCount }} fans who beat it win {{ $prize }} each.</p></div>
            <button class="md-primary md-griot-cta" type="submit">{{ $calls ? 'Update my calls' : 'Lock my 3 calls' }}</button>
            @guest<p class="md-griot-fine">No account needed to play. To be drawn for the data you need a free account before the last match ends: <a href="{{ route('register') }}">create one</a> and your calls come with you.</p>@endguest
        </form>
    @else
        <div class="md-panel"><h2>The Griot is listening to the drums</h2><p>Its next three calls appear as soon as the next Premier League round's fixtures are set. Turn on alerts and we'll tell you.</p><a class="md-primary" href="{{ route('matches.index') }}">See the fixtures</a></div>
    @endif

    @if($last)
        <section class="md-griot-last" aria-labelledby="griot-last">
            <h2 id="griot-last">Last round: {{ $last->datesLabel() }}</h2>
            @if($lastEntry)
                <div class="md-griot-versus"><div><b>{{ (int) $last->griot_points }}</b><small>GRIOT</small></div><span>VS</span><div @class(['is-win' => $lastEntry->beat])><b>{{ $lastEntry->points }}</b><small>YOU</small></div></div>
                <p class="md-griot-verdict @if($lastEntry->beat) is-win @endif">{{ $lastEntry->beat ? 'You beat the Griot!' : 'The Griot won this one.' }}</p>
            @endif
            <div class="md-griot-table-wrap"><table class="md-griot-table">
                <thead><tr><th>Match</th><th>FT</th><th>Griot</th>@if($lastEntry)<th>You</th>@endif</tr></thead>
                <tbody>@foreach($last->picks as $pick)@php($m = $pick->match)@php($done = $m?->isFinished())
                    <tr><td>{{ $m?->homeTeam?->short_code ?: Str::limit((string) $m?->homeTeam?->name, 12) }} v {{ $m?->awayTeam?->short_code ?: Str::limit((string) $m?->awayTeam?->name, 12) }}</td>
                    <td>{{ $done ? $m->home_score.'–'.$m->away_score : 'Void' }}</td>
                    <td>{{ $pick->call() }}@if($done) · {{ \App\Services\Griot\GriotChallengeService::points($pick->home_pick, $pick->away_pick, (int) $m->home_score, (int) $m->away_score) }}@endif</td>
                    @if($lastEntry)@php($c = $lastEntry->picks[$m?->id] ?? null)<td>{{ $c ? $c[0].'–'.$c[1].' · '.$c[2] : '–' }}</td>@endif</tr>
                @endforeach</tbody>
            </table></div>
            <p>{{ number_format($last->players) }} {{ Str::plural('fan', $last->players) }} played, {{ number_format($last->beaters) }} beat the Griot.</p>
            @if($last->winners->isNotEmpty())
                <h3>This round's {{ $last->winners->count() }} {{ Str::plural('winner', $last->winners->count()) }} of {{ $prize }}</h3>
                <ul class="md-griot-winners">@foreach($last->winners as $w)<li>{{ \App\Services\Griot\GriotChallengeService::publicName($w->user) }}@if($w->credited_at) <small>credited</small>@endif</li>@endforeach</ul>
                <p class="md-griot-fine">Winners: check your Matchday inbox for your code, then message us on @foreach($accounts as $a)<a href="{{ $a['url'] }}" rel="noopener" target="_blank">{{ $a['name'] }} ({{ $a['handle'] }})</a>@if(!$loop->last) or @endif @endforeach.</p>
            @endif
        </section>
    @endif

    <details class="md-griot-rules"><summary>Rules</summary>
        <ul>
            <li>Every Premier League round the Griot picks three matches and calls each score.</li>
            <li>Call all three before each kick-off. You can change a call until its match kicks off.</li>
            <li>Exact score: 3 points. Right result: 1 point. Score more points than the Griot to beat it; a tie doesn't.</li>
            <li>After the last match, {{ $winnersCount }} fans who beat the Griot are drawn at random to win {{ $prize }} each. If fewer beat it, they all win.</li>
            <li>Winners need an account and a {{ config('griot_challenge.country') }} number on {{ implode(', ', config('griot_challenge.networks')) }}. One prize per person per round.</li>
            <li>A postponed match doesn't count: the round is scored on the matches that were played.</li>
            <li>Free to play. Matchday Africa never asks for payment, a PIN or a password.</li>
        </ul>
    </details>
    @php($share = \App\Support\ShareLink::links(route('griot.challenge'), 'The Griot has made its calls. Can you beat it? Win '.$prize.' 👇', 'griot'))
    <a class="md-secondary md-griot-share" href="{{ $share['whatsapp'] }}" target="_blank" rel="noopener">Dare a friend on WhatsApp</a>
</div></section>
@endsection
