@extends('layouts.public')
@section('content')
<section class="md-reels md-reel-page" aria-labelledby="video-title">
    <div class="md-wrap md-reels-grid">
        <div class="md-reels-player">
            <video controls playsinline preload="metadata" @if($video->posterUrl()) poster="{{ $video->posterUrl() }}" @endif src="{{ $video->videoUrl() }}" aria-label="{{ $video->title }}">Your browser can't play this video.</video>
        </div>
        <div class="md-reels-copy">
            <p class="md-eyebrow">{{ strtoupper($video->typeLabel()) }}@if($video->lengthLabel()) · {{ strtoupper($video->lengthLabel()) }}@endif</p>
            <h1 id="video-title">{{ $video->title }}</h1>
            @if($video->description)
                @foreach(preg_split('/\n\s*\n/', trim($video->description)) as $para)<p class="md-reels-now">{!! nl2br(e($para)) !!}</p>@endforeach
            @elseif($video->caption)
                <p class="md-reels-now">{{ \App\Services\SocialQueue::plain($video->caption) }}</p>
            @endif
            <div class="md-home-actions">
                @if($cta)<a class="md-primary" href="{{ $cta['href'] }}">{{ $cta['label'] }}</a>@endif
                <a class="md-secondary" href="{{ route('reels.index') }}">More videos and reels</a>
            </div>
            @if($cta)<p class="md-reels-now">{{ $cta['line'] }}</p>@endif
            @guest
                <p class="md-reels-now">Sign up free to save your calls and get match alerts.</p>
                @include('partials.signup-options', ['return' => $cta['href'] ?? route('reels.video', $video, false)])
            @endguest
            @if($video->credits)<p class="md-reels-now"><small>Credits: {{ $video->credits }}</small></p>@endif
            @if($video->tiktok_url || $video->youtube_url)
                <p class="md-reels-now"><small>Also on
                    @if($video->tiktok_url)<a href="{{ $video->tiktok_url }}" target="_blank" rel="noopener">TikTok</a>@endif
                    @if($video->tiktok_url && $video->youtube_url) and @endif
                    @if($video->youtube_url)<a href="{{ $video->youtube_url }}" target="_blank" rel="noopener">YouTube</a>@endif
                </small></p>
            @endif
            <x-share-bar :url="$video->pageUrl()" :text="'Watch '.$video->title.' on Matchday Africa.'" campaign="video" />
            @if($more->isNotEmpty())
                <p class="md-eyebrow">MORE TO WATCH</p>
                <div class="md-reels-rail" role="list" aria-label="More videos">
                    @foreach($more as $other)
                        <a role="listitem" class="md-reels-thumb" href="{{ $other['page'] }}">
                            @if($other['poster'])<img src="{{ $other['poster'] }}" alt="" loading="lazy" width="108" height="192">@endif
                            <span>{{ $other['title'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
<x-social-follow variant="block" context="reel" />
@endsection
