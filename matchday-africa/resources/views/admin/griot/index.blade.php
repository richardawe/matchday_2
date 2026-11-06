@extends('layouts.admin')
@section('title','Beat the Griot')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">BEAT THE GRIOT</p><h1>Rounds and winners.</h1><p>The Griot plans each Premier League round's three calls and scores them by itself. After each round {{ (int) config('griot_challenge.winners') }} fans who beat it are drawn at random. They message us on X or Instagram with their code, number and network; credit the data from your phone, then record it here.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if($errors->any())<p class="md-notice md-flash-error">{{ $errors->first() }}</p>@endif
 @if($ready && $inbox && $everyone > 0)
  <details class="md-griot-message">
   <summary>Message everyone who has played ({{ number_format($everyone) }} {{ \Illuminate\Support\Str::plural('fan', $everyone) }} with an account)</summary>
   @include('admin.griot.message-form', ['action' => route('admin.griot.message'), 'id' => 'all', 'count' => $everyone])
  </details>
 @endif
 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>), then set <code>GRIOT_CHALLENGE=true</code>.</p></section>
 @elseif(!$enabled)
  <section class="md-xguide"><span>SWITCHED OFF</span><p>Set <code>GRIOT_CHALLENGE=true</code> in <code>.env</code> and clear the config cache. The page, the home strip and <code>griot:challenge</code> stay quiet until then.</p></section>
 @endif

 @forelse($challenges as $c)
  <header class="md-xsection"><div><p class="md-eyebrow">ROUND #{{ $c->id }} · {{ mb_strtoupper($c->datesLabel()) }} · {{ mb_strtoupper($c->status) }}</p><h2>@if($c->status === 'scored')Griot {{ (int) $c->griot_points }} pts · {{ $c->players }} played · {{ $c->beaters }} beat it @else Open: calls close at each kick-off @endif</h2></div>@if($c->status === 'scored')<a href="{{ \App\Services\Griot\ChallengeCardRenderer::cardUrl($c, 'verdict') }}" target="_blank" rel="noopener">Verdict card</a>@else<a href="{{ \App\Services\Griot\ChallengeCardRenderer::cardUrl($c, 'calls') }}" target="_blank" rel="noopener">Calls card</a>@endif</header>
  <table class="md-signup-table"><thead><tr><th>Match</th><th>Kick-off</th><th>Griot</th><th>Result</th></tr></thead><tbody>
   @foreach($c->picks as $p)<tr><td>{{ $p->match?->homeTeam?->name }} v {{ $p->match?->awayTeam?->name }}</td><td>{{ $p->match?->kickoffLabel('D j M H:i') }}</td><td>{{ $p->call() }}</td><td>{{ $p->match?->isFinished() ? $p->match->home_score.'–'.$p->match->away_score : $p->match?->status }}</td></tr>@endforeach
  </tbody></table>
  @php($fans = $callers[$c->id] ?? collect())
  @php($guestCount = $guests[$c->id] ?? 0)
  <details class="md-griot-fans" @if($c->status !== 'scored') open @endif>
   <summary><b>{{ number_format($fans->count()) }} {{ \Illuminate\Support\Str::plural('fan', $fans->count()) }} with an account</b> made calls this round @if($guestCount)· plus {{ number_format($guestCount) }} {{ \Illuminate\Support\Str::plural('guest', $guestCount) }} without an account (they can't get messages)@endif</summary>
   @if($fans->isNotEmpty())
    <table class="md-signup-table"><thead><tr><th>Fan</th><th>Email</th><th>Calls</th><th>Last call</th></tr></thead><tbody>
     @foreach($fans as $f)
      <tr><td>{{ $f['user']->name }}@if($f['user']->username) ({{ '@'.$f['user']->username }})@endif</td><td>{{ $f['user']->email }}</td>
       <td>{{ $f['calls'] }} of {{ $c->picks->count() }}</td><td>{{ $f['last']?->timezone('Africa/Lagos')->format('D j M H:i') }}</td></tr>
     @endforeach
    </tbody></table>
    @if($inbox)
     <details class="md-griot-message"><summary>Message this round's {{ number_format($fans->count()) }} {{ \Illuminate\Support\Str::plural('fan', $fans->count()) }}</summary>
      @include('admin.griot.message-form', ['action' => route('admin.griot.message.round', $c), 'id' => $c->id, 'count' => $fans->count()])
     </details>
    @endif
   @else<p>No calls from fans with an account yet.</p>@endif
  </details>
  @if($c->winners->isNotEmpty())
   <table class="md-signup-table"><thead><tr><th>Code</th><th>Winner</th><th>Email</th><th>Credited</th></tr></thead><tbody>
    @foreach($c->winners as $w)
     <tr><td><b>{{ $w->code }}</b></td><td>{{ $w->user?->name }}@if($w->user?->username) ({{ '@'.$w->user->username }})@endif</td><td>{{ $w->user?->email }}</td>
      <td>@if($w->credited_at){{ $w->network }} {{ $w->phone }} · {{ $w->credited_at->format('j M H:i') }} by {{ $w->creditedBy?->name }}@if($w->note)<br><small>{{ $w->note }}</small>@endif
       @else<form method="POST" action="{{ route('admin.griot.credit', $w) }}" class="md-griot-credit">@csrf
        <input name="phone" required maxlength="32" placeholder="Phone number" aria-label="Phone number for {{ $w->code }}">
        <select name="network" required aria-label="Network for {{ $w->code }}">@foreach(config('griot_challenge.networks') as $n)<option>{{ $n }}</option>@endforeach</select>
        <input name="note" maxlength="500" placeholder="Note (optional)" aria-label="Note for {{ $w->code }}">
        <button class="md-primary" type="submit">Mark credited</button></form>@endif</td></tr>
    @endforeach
   </tbody></table>
  @elseif($c->status === 'scored')<p>Nobody beat the Griot this round.</p>@endif
 @empty
  @if($ready)<section class="md-xguide"><span>NO ROUNDS YET</span><p>The first round appears when the next Premier League fixtures are within a week (checked every ten minutes).</p></section>@endif
 @endforelse
</div>
@endsection
