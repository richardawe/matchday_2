<!-- Primary Meta Tags -->
<title>{{ $metaData['title'] }}</title>
<meta name="description" content="{{ $metaData['description'] }}">
<meta name="robots" content="{{ $metaData['robots'] ?? 'index, follow' }}">
<link rel="canonical" href="{{ $metaData['url'] }}">

<!-- Open Graph -->
<meta property="og:locale" content="en_GB">
@foreach($metaData['open_graph'] as $property => $value)
<meta property="{{ $property }}" content="{{ $value }}">
@endforeach

<!-- Twitter / X -->
@foreach($metaData['twitter_card'] as $name => $value)
<meta name="{{ $name }}" content="{{ $value }}">
@endforeach

@if(!empty($metaData['json_ld']))
<script type="application/ld+json">{!! json_encode($metaData['json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endif

<!-- Favicon -->
<x-favicon />

<!-- Mobile App Meta Tags -->
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Matchday Africa">
