@extends('layouts.admin')

@section('title', 'Prediction Seasons')

@section('header')
<div><p class="md-eyebrow">PREDICTION CONTROL</p><h1>Season management</h1><p class="text-gray-600 mt-2">Close the current competition and prepare a clean prediction table.</p></div>
@endsection

@section('content')
<div class="md-season-admin">
    @if(session('success'))<div class="md-notice">{{ session('success') }}</div>@endif

    <section class="md-season-current">
        <div><span>CURRENT SEASON</span><h2>{{ $currentSeason?->name ?? 'No season has been started' }}</h2><p>{{ $currentSeason ? 'Started '.$currentSeason->started_at->format('d M Y · H:i') : 'Use the control below to create the first recorded prediction season.' }}</p></div>
        <a class="md-secondary" href="{{ route('admin.predictions.index') }}">Manage challenges</a>
    </section>

    <div class="md-season-grid">
        <section class="md-season-danger">
            <p class="md-eyebrow">IRREVERSIBLE ACTION</p>
            <h2>Start a new season</h2>
            <p>This permanently clears every submitted prediction and leaderboard entry. All current prediction challenges are archived. Users, fixtures, teams and articles are preserved.</p>

            <div class="md-season-impact">
                <div><strong>{{ number_format($counts['predictions']) }}</strong><span>predictions deleted</span></div>
                <div><strong>{{ number_format($counts['leaderboards']) }}</strong><span>ranking rows deleted</span></div>
                <div><strong>{{ number_format($counts['prediction_sets']) }}</strong><span>challenges archived</span></div>
            </div>

            <form method="POST" action="{{ route('admin.predictions.season.store') }}" onsubmit="return confirm('This permanently deletes all existing prediction records. Start the new season?')">
                @csrf
                <label><span>New season name</span><input name="name" value="{{ old('name', now()->year.'/'.(now()->year + 1)) }}" maxlength="100" required></label>
                <label><span>Type START NEW SEASON</span><input name="confirmation" value="{{ old('confirmation') }}" autocomplete="off" required></label>
                <label class="md-season-check"><input type="checkbox" name="acknowledge_deletion" value="1" required><span>I understand existing predictions and rankings cannot be recovered from this control.</span></label>
                @if($errors->any())<div class="md-season-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <button type="submit">Start new season and clear predictions</button>
            </form>
        </section>

        <section class="md-season-history">
            <p class="md-eyebrow">AUDIT LOG</p><h2>Season history</h2>
            @forelse($seasons as $season)
                <article><div><strong>{{ $season->name }}</strong><span>{{ $season->is_active ? 'ACTIVE' : 'CLOSED' }}</span></div><p>Started {{ $season->started_at->format('d M Y · H:i') }} by {{ $season->starter?->name ?? 'Admin' }}</p><small>{{ number_format($season->cleared_predictions) }} predictions · {{ number_format($season->cleared_leaderboard_entries) }} rankings · {{ number_format($season->archived_prediction_sets) }} challenges cleared at launch</small></article>
            @empty
                <p class="md-season-empty">No recorded season changes yet.</p>
            @endforelse
        </section>
    </div>
</div>
@endsection
