@extends('layouts.public')
@section('meta_title', 'Your alerts')
@section('meta_robots', 'noindex, nofollow')
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow md-inbox">
    <p class="md-eyebrow">YOUR ALERTS</p>
    <h1>Inbox</h1>

    <div class="md-panel md-inbox-push" data-push-panel data-vapid="{{ $pushReady ? config('push.vapid.public_key') : '' }}">
        @if($pushReady)
            <div><strong>Alerts on this phone</strong><p data-push-status>Goals, red cards and full time from the matches you follow, even with Matchday Africa closed.</p></div>
            <button type="button" class="md-primary" data-push-enable>Turn on alerts here</button>
        @else
            <div><strong>Alerts in your inbox</strong><p>Phone alerts are coming soon. Until then, everything you follow lands here.</p></div>
        @endif
    </div>

    @if($alerts->isNotEmpty())
        <h2 class="md-inbox-heading">Matches you follow</h2>
        <ul class="md-inbox-follow">
            @foreach($alerts as $alert)
                <li><a href="{{ route('matches.show', $alert->match) }}">{{ $alert->match->homeTeam?->name }} v {{ $alert->match->awayTeam?->name }}</a>
                    <button type="button" class="md-link" data-alert-toggle data-url="{{ route('matches.alert', $alert->match) }}" data-on="1">Stop alerts</button></li>
            @endforeach
        </ul>
    @endif

    <div class="md-inbox-head">
        <h2 class="md-inbox-heading">Latest</h2>
        @if(auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('notifications.read') }}">@csrf<button class="md-link">Mark all read</button></form>
        @endif
    </div>
    @forelse($notifications as $note)
        <a class="md-inbox-item {{ $note->read_at ? '' : 'is-unread' }}" href="{{ route('notifications.open', $note->id) }}">
            <span class="md-inbox-kind">{{ match(data_get($note->data, 'type')) { 'match_alert' => '⚽', 'announcement' => '📣', default => '🔔' } }}</span>
            <span><strong>{{ data_get($note->data, 'title', 'Matchday Africa') }}</strong>
                <span>{{ data_get($note->data, 'body', data_get($note->data, 'message')) }}</span>
                <small>{{ $note->created_at->diffForHumans() }}</small></span>
        </a>
    @empty
        <p class="md-inbox-empty">Nothing yet. Press <strong>Alert me</strong> on any match and its goals, red cards and result will arrive here.</p>
    @endforelse
    {{ $notifications->links() }}
</div></section>
@endsection
