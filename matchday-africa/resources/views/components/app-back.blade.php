{{-- Back button for the installed app (resources/js/install.js): a home-screen app has no browser
     back button, so this shows only in standalone mode and never on the home page. Goes back
     within the site, or home when there is nothing to go back to. --}}
@unless(request()->routeIs('home'))
<a href="{{ route('home') }}" class="md-app-back {{ $attributes->get('class') }}" data-app-back aria-label="Back">
    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg><span>Back</span>
</a>
@endunless
