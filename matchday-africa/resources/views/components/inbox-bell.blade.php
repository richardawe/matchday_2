{{-- The bell: unread inbox entries (match alerts and messages from Matchday Africa). --}}
@if(app(\App\Services\Alerts\Alerts::class)->ready())
    @php($unread = auth()->user()->unreadNotifications()->count())
    <a href="{{ route('notifications.index') }}" class="md-bell me-2" aria-label="Inbox{{ $unread ? ", {$unread} unread" : '' }}">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg>
        @if($unread)<span class="md-bell-count">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
    </a>
@endif
