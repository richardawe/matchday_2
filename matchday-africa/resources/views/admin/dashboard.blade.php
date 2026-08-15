@extends('layouts.admin')
@section('title','Command Centre')
@section('header')<div class="md-admin-heading"><div><p class="md-eyebrow">MATCHDAY OPERATIONS</p><h1>Command centre.</h1><p>Fixtures, live-data freshness, publishing and prediction health in one place.</p></div><span class="{{ ($operations['stale_live']+$operations['finished_missing_score']) ? 'warn' : 'good' }}">{{ ($operations['stale_live']+$operations['finished_missing_score']) ? 'ATTENTION NEEDED' : 'SYSTEM HEALTHY' }}</span></div>@endsection
@section('content')
<div class="md-ops">
    <section class="md-ops-stats">
        <div><span>LIVE MATCHES</span><strong>{{ number_format($stats['matches']['live']) }}</strong><small>{{ $operations['stale_live'] }} stale</small></div>
        <div><span>TODAY'S FIXTURES</span><strong>{{ number_format($stats['matches']['today']) }}</strong><small>{{ $operations['events_today'] }} events updated</small></div>
        <div><span>MISSING SCORES</span><strong>{{ number_format($operations['finished_missing_score']) }}</strong><small>Finished records</small></div>
        <div><span>NEWS TODAY</span><strong>{{ number_format($operations['news_today']) }}/2</strong><small>Automated briefs</small></div>
    </section>

    <section class="md-ops-grid">
        <div class="md-ops-panel">
            <header><div><p class="md-eyebrow">DATA PIPELINE</p><h2>Recent activity</h2></div><a href="{{ route('admin.sync.index') }}">Sync controls →</a></header>
            <div class="md-run-list">@forelse($syncRuns as $run)<div><i class="{{ $run->status }}"></i><span><strong>{{ $run->task }}</strong><small>{{ $run->message ?: ucfirst($run->status) }}</small></span><time>{{ $run->started_at->diffForHumans() }}</time></div>@empty<div class="md-ops-empty">No scheduler runs recorded yet. Run the production migration and scheduler to begin monitoring.</div>@endforelse</div>
        </div>
        <aside class="md-health-panel">
            <p class="md-eyebrow">HEALTH CHECK</p><h2>What needs attention</h2>
            <div><span>Stale live matches</span><strong>{{ $operations['stale_live'] }}</strong></div>
            <div><span>Finals without scores</span><strong>{{ $operations['finished_missing_score'] }}</strong></div>
            <div><span>Recent finals without events</span><strong>{{ $operations['finished_missing_events'] }}</strong></div>
            <div><span>Football API</span><strong>{{ $apiStatus['is_configured'] ? 'Ready' : 'Missing key' }}</strong></div>
            <small>Last successful run: {{ $operations['last_success'] ? \Carbon\Carbon::parse($operations['last_success'])->diffForHumans() : 'Never recorded' }}</small>
        </aside>
    </section>

    <section class="md-ops-actions"><header><p class="md-eyebrow">ADMIN TOOLS</p><h2>Run the club.</h2></header><div>
        <a href="{{ route('admin.matches.index') }}"><b>01</b><strong>Match desk</strong><span>Fixtures, results and scoring →</span></a>
        <a href="{{ route('admin.predictions.index') }}"><b>02</b><strong>Prediction control</strong><span>Challenges and analytics →</span></a>
        <a href="{{ route('admin.blogs.index') }}"><b>03</b><strong>Newsroom</strong><span>Stories and automated briefs →</span></a>
        <a href="{{ route('admin.predictions.season.index') }}"><b>04</b><strong>Season management</strong><span>Safe competition reset →</span></a>
        <a href="{{ route('admin.creators.index') }}"><b>05</b><strong>Creator council</strong><span>Review submissions →</span></a>
        <a href="{{ route('admin.commerce.index') }}"><b>06</b><strong>Commerce</strong><span>Products and revenue →</span></a>
    </div></section>
</div>
@endsection
