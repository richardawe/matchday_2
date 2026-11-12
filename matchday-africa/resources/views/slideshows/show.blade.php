@extends('layouts.public')
@section('content')
<section class="md-reels md-reel-page" aria-labelledby="slideshow-title">
    <div class="md-wrap md-reels-grid">
        <div class="md-reels-player">
            <video controls playsinline preload="metadata" @if($show->posterUrl()) poster="{{ $show->posterUrl() }}" @endif src="{{ $show->videoUrl() }}" aria-label="{{ $show->title() }}: {{ $show->label() }} slideshow">Your browser can't play this video.</video>
        </div>
        <div class="md-reels-copy">
            <p class="md-eyebrow">SLIDESHOW · {{ strtoupper($show->label()) }}@if($match?->league) · {{ strtoupper($match->league->name) }}@endif</p>
            <h1 id="slideshow-title">{{ data_get($show->facts, 'title', $show->title()) }}</h1>
            @if($show->player)<p class="md-reels-now">Featuring {{ $show->player->name }}@if($label = \App\Support\AfricanScope::label($show->player)) ({{ $label }})@endif.</p>@endif
            @if($cta)
                <div class="md-home-actions">
                    <a class="md-primary" href="{{ $cta['href'] }}">{{ $cta['label'] }}</a>
                    @if($match)<a class="md-secondary" href="{{ route('matches.show', $match) }}">Match page</a>@endif
                </div>
            @endif
            <p class="md-reels-now">No account needed to make your call. Sign up to save your picks and get match alerts.</p>
            @guest
                @include('partials.signup-options', ['return' => $cta['href'] ?? route('slideshows.show', $show, false)])
            @endguest
            <x-share-bar :url="$show->pageUrl()" :text="data_get($show->facts, 'title', $show->title()).' on Matchday Africa.'" campaign="slideshow" />
            <p class="md-eyebrow">CREDITS</p>
            <ul class="md-slideshow-credits">
                @if($musicCredit)<li>{{ $musicCredit }}@if(data_get($show->music, 'licence_url')) · <a href="{{ data_get($show->music, 'licence_url') }}" rel="noopener license" target="_blank">licence</a>@endif</li>@endif
                @if(data_get($show->photo, 'credit'))<li>Photo: <a href="{{ data_get($show->photo, 'source_url') }}" rel="noopener" target="_blank">{{ data_get($show->photo, 'credit') }}</a></li>@endif
                <li>Facts and numbers from Matchday Africa's match data.</li>
            </ul>
            @if($more->isNotEmpty())
                <p class="md-eyebrow">MORE SLIDESHOWS</p>
                <div class="md-reels-rail" role="list" aria-label="More slideshows">
                    @foreach($more as $other)
                        <a role="listitem" class="md-reels-thumb" href="{{ $other->pageUrl() }}">
                            @if($other->posterUrl())<img src="{{ $other->posterUrl() }}" alt="" loading="lazy" width="108" height="192">@endif
                            <span>{{ data_get($other->facts, 'title', $other->title()) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
<x-social-follow variant="block" context="reel" />
@endsection
