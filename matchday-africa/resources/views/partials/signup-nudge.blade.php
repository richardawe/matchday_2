{{-- A light signup nudge for visitors: after 3 pages or a reel play (see the script in layouts/public). --}}
<aside id="md-nudge" class="md-nudge" role="dialog" aria-labelledby="md-nudge-title" aria-modal="false" hidden>
    <button type="button" class="md-nudge-close" data-nudge-dismiss aria-label="Not now">×</button>
    <p class="md-eyebrow">FREE ACCOUNT</p>
    <h2 id="md-nudge-title">Enjoying the match? Join free.</h2>
    <ul>
        <li><b>Free predictions league</b> Call the scores, beat your friends, top the weekly table.</li>
        <li><b>Match alerts</b> Kick-off, goals and full time for the teams you follow.</li>
        <li><b>Matchday Radio</b> Football bulletins at 6am, noon and 6pm.</li>
    </ul>
    <div class="md-nudge-actions">
        @if(config('services.google.client_id'))
            <a class="md-google" href="{{ route('auth.google.start', ['return' => request()->getRequestUri()]) }}" data-nudge-cta="google">Continue with Google</a>
        @endif
        <a class="md-primary" href="{{ route('register') }}" data-nudge-cta="email">Sign up with email</a>
        <button type="button" class="md-nudge-later" data-nudge-dismiss>Not now</button>
    </div>
</aside>
