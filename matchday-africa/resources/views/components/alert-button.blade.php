@props(['match'])
{{-- "Alert me" on a match. Alerts need an account: guests are told so, with sign-up and log-in links. --}}
@php($ready = app(\App\Services\Alerts\Alerts::class)->ready())
@if($ready && !$match->isFinished())
    @auth
        @php($on = \App\Models\MatchAlert::where(['user_id' => auth()->id(), 'match_id' => $match->id])->exists())
        <button type="button" class="md-secondary md-alert-button {{ $on ? 'is-on' : '' }}" data-alert-toggle data-url="{{ route('matches.alert', $match) }}" data-on="{{ $on ? '1' : '0' }}"
            data-vapid="{{ app(\App\Services\Alerts\Alerts::class)->pushReady() ? config('push.vapid.public_key') : '' }}">
            <span data-alert-label>{{ $on ? '🔔 Alerts on' : '🔔 Alert me' }}</span>
        </button>
    @else
        <span class="md-alert-guest" x-data="{ open: false }">
            <button type="button" class="md-secondary md-alert-button" @click="open = !open">🔔 Alert me</button>
            <span class="md-alert-note" x-show="open" x-cloak @click.away="open = false">
                You need an account to get match alerts.
                <a href="{{ route('register') }}">Create one free</a> or <a href="{{ route('login') }}">log in</a>.
            </span>
        </span>
    @endauth
@endif
