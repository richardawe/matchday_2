@extends('layouts.public')
@section('meta_title', 'Join '.$group->name.', a football prediction league | Matchday Africa')
@section('meta_description', 'You\'re invited to '.$group->name.' on Matchday Africa. Call the scores, top the weekly table. Free to join.')
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow">
    <p class="md-eyebrow">YOU'RE INVITED · PREDICTION LEAGUE</p>
    <h1>Join {{ $group->name }}</h1>
    <p class="md-lead">{{ $group->owner?->name ?? 'A fan' }} wants you in their league. {{ $group->members_count }} {{ Str::plural('contender', $group->members_count) }} call the scores each week; the table resets every Monday.</p>
    @if($rows->isNotEmpty())
        <p class="md-eyebrow">THIS WEEK'S TOP 5</p>
        <div class="md-table">@foreach($rows as $row)<div><b>{{ $loop->iteration }}</b><span>{{ $row['user']->name }}</span><strong>{{ $row['points'] }} pts</strong></div>@endforeach</div>
    @endif
    @auth
        <form method="POST" action="{{ route('groups.join') }}">@csrf<input type="hidden" name="code" value="{{ $group->code }}"><button class="md-primary">Join the league</button></form>
    @else
        <div class="md-panel md-join-panel">
            <h2>Free to join. One tap.</h2>
            <p>Free predictions league, match alerts and Matchday Radio.</p>
            @include('partials.signup-options', ['return' => route('groups.show', $group, false)])
        </div>
    @endauth
</div></section>
@endsection
