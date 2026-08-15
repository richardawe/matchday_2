@extends('layouts.app')

@section('content')
<section class="md-pred-subhero"><div class="md-wrap"><div><p class="md-eyebrow">YOUR RECORD</p><h1>Every call.<br><em>Every result.</em></h1><p>A complete, chronological record of your submitted predictions and the points awarded after each match is settled.</p></div><a class="md-secondary" href="{{ route('predictions.index') }}">← Predictions home</a></div></section>

<section class="md-pred-stats"><div class="md-wrap"><div><span>CALLS MADE</span><strong>{{ number_format($userStats['total_predictions']) }}</strong><small>All submissions</small></div><div><span>CORRECT CALLS</span><strong>{{ number_format($userStats['correct_predictions']) }}</strong><small>Settled as correct</small></div><div><span>ACCURACY</span><strong>{{ number_format($userStats['accuracy_percentage'], 0) }}%</strong><small>Correct across all calls</small></div><div><span>POINTS WON</span><strong>{{ number_format($userStats['total_points']) }}</strong><small>All-time total</small></div></div></section>

<section class="md-pred-ledger"><div class="md-wrap">
    <form method="GET" class="md-pred-filters">
        <label><span>Prediction round</span><select name="prediction_set_id"><option value="">All rounds</option>@foreach($predictionSets as $set)<option value="{{ $set->id }}" @selected(($filters['prediction_set_id'] ?? '') == $set->id)>{{ $set->name }}</option>@endforeach</select></label>
        <label><span>Submitted from</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
        <label><span>Submitted to</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
        <label><span>Outcome</span><select name="is_correct"><option value="">All outcomes</option><option value="1" @selected(($filters['is_correct'] ?? '') === '1')>Correct</option><option value="0" @selected(($filters['is_correct'] ?? '') === '0')>Incorrect</option></select></label>
        <button class="md-primary" type="submit">Apply filters</button>
        @if(array_filter($filters, fn($value) => $value !== null && $value !== ''))<a href="{{ route('predictions.history') }}">Clear filters</a>@endif
    </form>

    <header class="md-pred-heading"><div><p class="md-eyebrow">CALL LOG</p><h2>{{ number_format($predictions->total()) }} {{ Str::plural('prediction', $predictions->total()) }}</h2></div></header>
    <div class="md-history-list">
        @forelse($predictions as $prediction)
            @php $settled = $prediction->is_correct !== null; @endphp
            <a href="{{ route('matches.show', $prediction->match) }}" class="md-history-row">
                <span class="{{ $prediction->is_correct === true ? 'won' : ($prediction->is_correct === false ? 'lost' : 'pending') }}">{{ $prediction->is_correct === true ? '✓' : ($prediction->is_correct === false ? '×' : '·') }}</span>
                <div class="md-history-match"><small>{{ $prediction->match->league?->name ?? 'Football' }} · {{ $prediction->predictionSet?->name ?? 'Challenge' }}</small><strong>{{ $prediction->match->homeTeam?->name ?? 'TBC' }} <i>v</i> {{ $prediction->match->awayTeam?->name ?? 'TBC' }}</strong><p>Played {{ $prediction->match->match_date->format('d M Y · H:i') }} · Submitted {{ ($prediction->submitted_at ?? $prediction->created_at)->format('d M · H:i') }}</p></div>
                <div class="md-history-call"><small>{{ ucfirst(str_replace('_', ' ', $prediction->prediction_type)) }}</small><strong>{{ $prediction->prediction_value }}</strong>@if($settled)<p>Final score: {{ $prediction->match->home_score ?? '—' }}–{{ $prediction->match->away_score ?? '—' }}</p>@else<p>Awaiting settlement</p>@endif</div>
                <b>{{ $settled ? ($prediction->points_earned > 0 ? '+'.number_format($prediction->points_earned).' pts' : '0 pts') : 'Pending' }}</b>
            </a>
        @empty
            <div class="md-pred-empty"><span>0</span><div><h3>No calls match these filters.</h3><p>Clear the filters or enter an open challenge to add to your record.</p><a href="{{ route('predictions.index') }}">View open challenges →</a></div></div>
        @endforelse
    </div>
    @if($predictions->hasPages())<div class="md-pred-pages">{{ $predictions->appends(request()->query())->links() }}</div>@endif
</div></section>
@endsection
