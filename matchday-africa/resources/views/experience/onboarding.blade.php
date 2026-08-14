@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow"><p class="md-eyebrow">PERSONALISE YOUR WATCHTOWER</p><h1>Choose the clubs you follow.</h1><p class="md-lead">Your home feed will prioritise their fixtures, live rooms and prediction deadlines.</p>
<form method="POST" action="{{ route('onboarding.teams') }}" class="md-team-grid">@csrf
@foreach($teams as $team)<label class="md-team-choice"><input type="checkbox" name="teams[]" value="{{ $team->id }}" {{ $selected->contains($team->id)?'checked':'' }}><span>@if($team->logo_url)<img src="{{ $team->logo_url }}" alt="">@endif<strong>{{ $team->display_name }}</strong><small>{{ $team->country_name ?: 'Football club' }}</small></span></label>@endforeach
<button class="md-primary md-full" type="submit">Build my matchday →</button></form></div></section>
@endsection
