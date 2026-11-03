<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Matchday Africa</title>
    <x-favicon />
    {{-- Shown during deploys too (503): skip the stylesheets rather than fail while the build is mid-upload. --}}
    @if(is_file(public_path('build/manifest.json')))
        @vite(['resources/css/app.css'])
        @if(\App\Support\SiteStyle::cartoon()) @vite('resources/css/cartoon.css') @endif
    @endif
</head>
<body class="{{ \App\Support\SiteStyle::bodyClass() }} md-error-page">
    <main class="md-error">
        <a href="{{ url('/') }}" class="matchday-wordmark" aria-label="Matchday Africa home"><span class="matchday-monogram">MD</span><span>MATCHDAY<small>AFRICA</small></span></a>
        <section class="md-error-card">
            <img class="md-error-army" src="{{ asset('war/army/moods/roman-legion-'.trim($__env->yieldContent('mood', 'dejected')).'.webp') }}" alt="" width="160" height="160">
            <p class="md-error-code">@yield('code')</p>
            <h1>@yield('heading')</h1>
            <p>@yield('message')</p>
            <div class="md-error-actions">
                <a class="md-primary" href="{{ url('/') }}">Back to Matchday</a>
                <a class="md-secondary" href="{{ url('/matches') }}">Today's matches</a>
            </div>
        </section>
    </main>
</body>
</html>
