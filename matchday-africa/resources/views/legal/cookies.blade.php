@extends('layouts.public')
@section('meta_title', 'Cookie policy | Matchday Africa')
@section('meta_description', 'The cookies and similar storage Matchday Africa uses, what each one does and how to control them.')
@section('content')
<article class="md-wrap md-radio-page md-legal"><p class="md-eyebrow">LAST UPDATED 31 OCTOBER 2026</p><h1>Cookie policy</h1>
<div class="md-radio-transcript">
<p>Cookies are small files a website stores in your browser. Matchday Africa uses a few to keep you signed in, remember your choices and understand how the site is used. See the <a href="{{ route('privacy') }}">privacy policy</a> for how we handle personal data.</p>

<h2>Essential</h2>
<table class="md-cookie-table"><thead><tr><th>Name</th><th>Purpose</th><th>Lasts</th></tr></thead><tbody>
<tr><td>{{ config('session.cookie') }}</td><td>Keeps you signed in and remembers this visit.</td><td>{{ config('session.lifetime') }} minutes</td></tr>
<tr><td>XSRF-TOKEN</td><td>Protects forms against cross-site request forgery.</td><td>{{ config('session.lifetime') }} minutes</td></tr>
<tr><td>remember_web_*</td><td>Keeps you signed in if you tick "Remember me".</td><td>Up to 5 years, or until you log out</td></tr>
<tr><td>md_guest</td><td>Holds predictions you make before signing up, so they can move to your account.</td><td>1 year</td></tr>
</tbody></table>

<h2>Preferences</h2>
<table class="md-cookie-table"><thead><tr><th>Name</th><th>Purpose</th><th>Lasts</th></tr></thead><tbody>
<tr><td>griot_voice</td><td>Remembers the language you chose for match story captions.</td><td>1 year</td></tr>
<tr><td>Browser storage (md_nudge)</td><td>Counts pages viewed so we only show the signup reminder occasionally.</td><td>Until you clear it</td></tr>
</tbody></table>

<h2>Analytics</h2>
<table class="md-cookie-table"><thead><tr><th>Name</th><th>Purpose</th><th>Lasts</th></tr></thead><tbody>
<tr><td>md_visitor</td><td>A random ID that links page views, reel plays and button clicks from the same browser.</td><td>1 year</td></tr>
<tr><td>md_attr</td><td>Remembers the link or site that first brought you here (UTM tags, referrer, landing page) so a signup can be credited to it.</td><td>90 days</td></tr>
<tr><td>_clck, _clsk, CLID, MUID</td><td>Microsoft Clarity: heatmaps and anonymised session replays.</td><td>Up to 1 year</td></tr>
</tbody></table>

<h2>Third parties</h2>
<p>Signing in with Google or X, and paying with Stripe, sets those companies' own cookies under their policies.</p>

<h2>Your choices</h2>
<p>You can block or delete cookies in your browser settings. Blocking essential cookies will stop sign-in and forms from working. To opt out of Clarity, see <a href="https://privacy.microsoft.com/privacystatement" rel="noopener">Microsoft's privacy statement</a>. Questions: <a href="mailto:info@3d7tech.com">info@3d7tech.com</a>.</p>
</div></article>
@endsection
