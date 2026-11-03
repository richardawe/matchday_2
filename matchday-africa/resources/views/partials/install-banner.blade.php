{{-- "Get the app" banner (resources/js/install.js): Install on Android, Share → Add to Home Screen
     on iPhone, then "Turn on match alerts" inside the installed app for signed-in fans. --}}
@php($alertsVapid = auth()->check() && app(\App\Services\Alerts\Alerts::class)->pushReady() ? config('push.vapid.public_key') : '')
<aside id="md-install" class="md-install" role="dialog" aria-labelledby="md-install-title" aria-modal="false" hidden
       data-install-track="{{ route('analytics.track') }}" data-install-page="{{ route('app.install') }}" data-vapid="{{ $alertsVapid }}">
    <img class="md-install-icon" src="{{ asset('icons/icon-192.png') }}" alt="" width="48" height="48">
    <div class="md-install-copy">
        <strong id="md-install-title" data-install-title>Get the Matchday app</strong>
        <span data-install-text>Scores, reels and goal alerts from your home screen.</span>
    </div>
    <button type="button" class="md-primary" data-install-go>Install</button>
    <button type="button" class="md-install-close" data-install-dismiss aria-label="Not now">×</button>
</aside>
