@extends('layouts.public')
@section('content')
<article class="md-article">
<header class="md-article-head"><div class="md-wrap md-narrow"><a href="{{ route('blogs.index') }}">← Newsroom</a><p class="md-eyebrow">{{ data_get($blog->metadata,'category','FOOTBALL') }}</p><h1>{{ $blog->title }}</h1><p class="md-article-deck">{{ $blog->excerpt }}</p><div class="md-article-byline"><span>By {{ $blog->author_name ?? 'Matchday Africa' }}</span><span>{{ $blog->formatted_published_date }}</span><span>{{ $blog->reading_time }}</span></div></div></header>
<figure class="md-article-image md-wrap"><img src="{{ $blog->featured_image_url }}" alt="{{ $blog->title }}"></figure>
<div class="md-wrap md-article-layout"><main class="md-article-copy">@php($hasHtml=$blog->content!==strip_tags($blog->content)) @if($hasHtml){!! $blog->content !!}@else{!! Str::markdown($blog->content) !!}@endif
@if(data_get($blog->metadata,'source_url'))<p class="md-source-note">Reporting source: <a href="{{ data_get($blog->metadata,'source_url') }}" rel="nofollow noopener" target="_blank">{{ data_get($blog->metadata,'source','Original report') }}</a>.</p>@endif
</main><aside class="md-article-rail"><p class="md-eyebrow">SHARE THE STORY</p><x-social-share-buttons :content="$blog" :show-counts="true" /><hr><strong>Keep reading the game.</strong><a href="{{ route('matches.index') }}">Live match centre →</a><a href="{{ route('predictions.index') }}">Make predictions →</a><a href="{{ route('war.index') }}">Enter The War →</a></aside></div>
</article>
@endsection
