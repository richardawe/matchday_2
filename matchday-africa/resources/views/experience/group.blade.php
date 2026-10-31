@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow">
    <p class="md-eyebrow">{{ $group->is_public ? 'PUBLIC' : 'PRIVATE' }} LEAGUE · CODE {{ $group->code }}</p>
    <h1>{{ $group->name }}</h1>
    @if(session('success'))<div class="md-notice">{{ session('success') }}</div>@endif
    <nav class="md-league-tabs" aria-label="Table period">
        <a href="{{ route('groups.show', $group) }}" @class(['is-active' => $period === 'week']) @if($period === 'week') aria-current="page" @endif>This week</a>
        <a href="{{ route('groups.show', ['group' => $group, 'period' => 'all']) }}" @class(['is-active' => $period === 'all']) @if($period === 'all') aria-current="page" @endif>All time</a>
    </nav>
    <p class="md-league-week">{{ $period === 'week' ? 'Matches from '.$weekStart->format('D j M').' to '.$weekEnd->format('D j M').'. The table resets every Monday.' : 'Every point since the league began.' }}</p>
    <div class="md-table">
        @foreach($rows as $row)
            <div @class(['is-me' => $row['user']->id === auth()->id()])><b>{{ $loop->iteration }}</b><span>{{ $row['user']->name }} <small>{{ $row['picks'] }} {{ Str::plural('pick', $row['picks']) }}</small></span><strong>{{ $row['points'] }} pts</strong></div>
        @endforeach
    </div>
    @if($member)
        <div class="md-panel md-league-invite">
            <h2>Invite your people</h2>
            <p>Anyone with this link can join. New fans sign up in one tap and land straight in the table.</p>
            <input type="text" readonly value="{{ $inviteUrl }}" aria-label="Invite link" onclick="this.select()">
            <a class="md-primary" href="https://wa.me/?text={{ rawurlencode('Join my prediction league '.$group->name.' on Matchday Africa: '.$inviteUrl) }}" target="_blank" rel="noopener">Share on WhatsApp</a>
        </div>
    @endif
    <a class="md-secondary" href="{{ route('matches.index') }}">Make this week's picks</a>
</div></section>
@endsection
