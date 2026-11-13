@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap">
    <p class="md-eyebrow">THE CONTINENT ABROAD AND AT HOME</p>
    <h1>African players and players of African descent</h1>
    <p class="md-lead">African internationals, and players whose African heritage our editors have confirmed with a source, in every league we cover.</p>
    <p class="md-home-actions"><a class="md-primary" href="{{ route('discovery.roster') }}">This week's Premier League roster</a> <a class="md-secondary" href="{{ route('discovery.history') }}">AFCON history</a></p>
    <form class="md-discovery-filter">
        <input name="search" value="{{ request('search') }}" placeholder="Search a player" aria-label="Search a player">
        <select name="country" aria-label="Country"><option value="">All nations</option>@foreach($countries as $c)<option value="{{ $c['code'] }}" {{ strtoupper((string) request('country')) === $c['code'] ? 'selected' : '' }}>{{ $c['name'] }}</option>@endforeach</select>
        <button class="md-primary">Explore</button>
    </form>
    <div class="md-player-grid">
        @forelse($players as $player)
            <a href="{{ route('discovery.show', $player) }}" class="md-player-card">
                @if($player->photo_url)<img src="{{ $player->photo_url }}" alt="{{ $player->name }}">@else<div class="md-player-placeholder">{{ strtoupper(substr($player->name, 0, 2)) }}</div>@endif
                <div><small>@if(\App\Support\AfricanScope::isNational($player)){{ $player->nationality_flag }} @endif{{ \App\Support\AfricanScope::label($player) }}</small><h2>{{ $player->name }}</h2><p>{{ $player->position }} · {{ $player->team?->name }}</p></div>
            </a>
        @empty
            <div class="md-panel">No players match this search.</div>
        @endforelse
    </div>
    {{ $players->links() }}
</div></section>
@endsection
