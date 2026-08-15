@extends('layouts.app')

@section('content')
<section class="md-pred-subhero md-board-hero"><div class="md-wrap"><div><p class="md-eyebrow">ALL-TIME STANDINGS</p><h1>Read the game.<br><em>Own the table.</em></h1><p>Rankings are ordered by points earned, with prediction accuracy used as the tie-breaker.</p></div><a class="md-secondary" href="{{ route('predictions.index') }}">← Predictions home</a></div></section>

<section class="md-pred-stats"><div class="md-wrap"><div><span>YOUR RANK</span><strong>{{ ($userStats['rank'] ?? 0) > 0 ? '#'.number_format($userStats['rank']) : '—' }}</strong><small>{{ $predictionSet?->name ?? 'All challenges' }}</small></div><div><span>YOUR POINTS</span><strong>{{ number_format($userStats['total_points'] ?? 0) }}</strong><small>All-time total</small></div><div><span>YOUR ACCURACY</span><strong>{{ number_format($userStats['accuracy_percentage'] ?? 0, 0) }}%</strong><small>Correct across all calls</small></div><div><span>CALLS MADE</span><strong>{{ number_format($userStats['total_predictions'] ?? 0) }}</strong><small>Submitted predictions</small></div></div></section>

<section class="md-board-body"><div class="md-wrap">
    <form method="GET" class="md-board-filters">
        <label><span>STANDINGS FOR</span><select name="prediction_set_id"><option value="">All challenges</option>@foreach($predictionSets ?? [] as $set)<option value="{{ $set->id }}" @selected(request('prediction_set_id') == $set->id)>{{ $set->name }}</option>@endforeach</select></label>
        <label><span>SHOW</span><select name="limit">@foreach([10,25,50,100] as $limit)<option value="{{ $limit }}" @selected((int)request('limit', 50) === $limit)>Top {{ $limit }}</option>@endforeach</select></label>
        <button class="md-primary" type="submit">Update table</button>
    </form>

    <header class="md-pred-heading"><div><p class="md-eyebrow">THE TABLE</p><h2>{{ $predictionSet?->name ?? 'Global standings' }}</h2></div><small>All-time · Updated after scoring</small></header>
    <div class="md-board-table">
        <div class="md-board-head"><span>Rank</span><span>Player</span><span>Points</span><span>Accuracy</span><span>Record</span></div>
        @forelse($leaderboard as $entry)
            @php $isUser = $entry->user_id === auth()->id(); @endphp
            <div class="md-board-row {{ $isUser ? 'is-user' : '' }}">
                <strong class="md-board-rank">#{{ number_format($entry->rank) }}</strong>
                <div class="md-board-player"><span>{{ Str::upper(Str::substr($entry->user?->name ?? 'Anonymous', 0, 2)) }}</span><div><strong>{{ $entry->user?->name ?? 'Anonymous supporter' }}</strong><small>{{ $isUser ? 'This is you' : 'Playing since '.($entry->user?->created_at?->format('M Y') ?? '—') }}</small></div></div>
                <strong class="md-board-points">{{ number_format($entry->total_points) }}</strong>
                <div class="md-board-accuracy"><strong>{{ number_format($entry->accuracy_percentage, 1) }}%</strong><span><i style="width: {{ min((float)$entry->accuracy_percentage, 100) }}%"></i></span></div>
                <div class="md-board-record"><strong>{{ number_format($entry->correct_predictions) }}</strong><small>correct / {{ number_format($entry->total_predictions) }} calls</small></div>
            </div>
        @empty
            <div class="md-board-empty"><strong>No ranked players yet.</strong><p>Rankings appear after submitted calls have been scored.</p><a href="{{ route('predictions.index') }}">Make your calls →</a></div>
        @endforelse
    </div>
    @if($leaderboard->hasPages())<div class="md-pred-pages">{{ $leaderboard->appends(request()->query())->links() }}</div>@endif
    <p class="md-board-note">Rankings show the stored all-time leaderboard. Points determine position; accuracy separates players on equal points.</p>
</div></section>
@endsection
