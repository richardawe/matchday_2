@extends('layouts.public')
@section('meta_title', 'Get the Matchday Africa app: scores, reels and goal alerts')
@section('meta_description', 'Add Matchday Africa to your iPhone or Android home screen in seconds. Free, no app store: live scores, 20-second reels, Matchday Radio and goal alerts.')
@php($alerts = app(\App\Services\Alerts\Alerts::class))
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow md-app" data-install-page data-install-track="{{ route('analytics.track') }}">
    <p class="md-eyebrow">THE APP</p>
    <h1>Matchday Africa on your phone</h1>
    <p class="md-lead">No app store and nothing to pay. Add Matchday Africa to your home screen: it opens full screen like any app, and sends kick-off, goals and full time for the matches you follow.</p>

    <div class="md-panel md-app-cta">
        <img src="{{ asset('icons/icon-192.png') }}" alt="" width="72" height="72">
        <div data-install-when="prompt">
            <strong>Ready to install</strong>
            <p>One tap and Matchday Africa sits on your home screen.</p>
            <button type="button" class="md-primary" data-install-go>Install the app</button>
        </div>
        <div data-install-when="installed" hidden>
            <strong>You’re in the app ✓</strong>
            <p>Now turn on alerts below and tap “Alert me” on the matches you care about.</p>
        </div>
        <div data-install-when="ios" hidden>
            <strong>On iPhone it takes three taps</strong>
            <p>Follow the iPhone steps below in Safari.</p>
        </div>
        <div data-install-when="manual">
            <strong>Install from your browser menu</strong>
            <p>Open this page in Chrome on Android (or Safari on iPhone) and follow the steps below.</p>
        </div>
    </div>

    <div class="md-two md-app-steps">
        <div class="md-panel" id="iphone">
            <h2>iPhone and iPad</h2>
            <ol>
                <li>Open <b>matchday.africa</b> in <b>Safari</b>.</li>
                <li>Tap <b>Share</b> <span aria-hidden="true">(the square with an arrow)</span>.</li>
                <li>Scroll down and tap <b>Add to Home Screen</b>, then <b>Add</b>.</li>
                <li>Open <b>Matchday</b> from your home screen and turn on alerts.</li>
            </ol>
            <small>Alerts on iPhone need iOS 16.4 or later, and only work in the app opened from the home screen.</small>
        </div>
        <div class="md-panel" id="android">
            <h2>Android</h2>
            <ol>
                <li>Open <b>matchday.africa</b> in <b>Chrome</b>.</li>
                <li>Tap <b>Install</b> when it’s offered, or the <b>⋮</b> menu → <b>Install app</b> (or <b>Add to Home screen</b>).</li>
                <li>Open <b>Matchday</b> from your home screen or app drawer.</li>
                <li>Turn on alerts and allow notifications.</li>
            </ol>
            <small>Works in Chrome, Samsung Internet and Edge.</small>
        </div>
    </div>

    <h2 class="md-inbox-heading">Match alerts</h2>
    @auth
        @if($alerts->pushReady())
            <div class="md-panel md-inbox-push" data-push-panel data-vapid="{{ config('push.vapid.public_key') }}">
                <div><strong>Alerts on this phone</strong><p data-push-status>Tap “Alert me” on any match, then turn alerts on here. Goals, red cards and full time arrive even with the app closed.</p></div>
                <button type="button" class="md-primary" data-push-enable>Turn on alerts here</button>
            </div>
        @else
            <div class="md-panel"><strong>Alerts in your inbox</strong><p>Phone alerts are coming soon. Until then, everything you follow lands in your inbox.</p></div>
        @endif
    @else
        <div class="md-panel md-inbox-push">
            <div><strong>Alerts need a free account</strong><p>Sign in, tap “Alert me” on any match and we’ll tell you about kick-off, goals and full time.</p></div>
            <div class="md-app-auth">
                <a class="md-primary" href="{{ route('register') }}">Join free</a>
                <a class="md-secondary" href="{{ route('login') }}">Sign in</a>
            </div>
        </div>
    @endauth
</div></section>
@endsection
