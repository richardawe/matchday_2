@props(['content' => null])

@php
    $metaData = $metaData ?? [];
@endphp

<!-- Primary Meta Tags -->
<title>{{ $metaData['title'] ?? 'Matchday Africa' }}</title>
<meta name="title" content="{{ $metaData['title'] ?? 'Matchday Africa' }}">
<meta name="description" content="{{ $metaData['description'] ?? 'Your ultimate destination for football match tracking, live scores, predictions, and more!' }}">

<!-- Open Graph / Facebook -->
@foreach($metaData['open_graph'] ?? [] as $property => $content)
    <meta property="{{ $property }}" content="{{ $content }}">
@endforeach

<!-- Twitter -->
@foreach($metaData['twitter_card'] ?? [] as $name => $content)
    <meta name="{{ $name }}" content="{{ $content }}">
@endforeach

<!-- Additional Meta Tags -->
<meta name="robots" content="index, follow">
<meta name="language" content="English">
<meta name="author" content="Matchday Africa">

<!-- Canonical URL -->
<link rel="canonical" href="{{ $metaData['url'] ?? config('app.url') }}">

<!-- Favicon -->
<x-favicon />

<!-- Additional Open Graph Tags -->
<meta property="og:locale" content="en_US">
<meta property="og:image:type" content="image/png">
<meta property="og:image:secure_url" content="{{ $metaData['image'] ?? asset('images/social-default.png') }}">

<!-- Additional Twitter Tags -->
<meta name="twitter:creator" content="@matchdayafrica">
<meta name="twitter:image:alt" content="{{ $metaData['title'] ?? 'Matchday Africa' }}">

<!-- Mobile App Meta Tags -->
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Matchday Africa">
