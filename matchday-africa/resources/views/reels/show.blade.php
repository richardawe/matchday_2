@extends('layouts.public')
@section('content')
<section class="md-reels md-reel-page" aria-labelledby="reel-title">
    <div class="md-wrap md-reels-grid">
        <div class="md-reels-player">
            <video controls playsinline preload="metadata" @if($reel['poster']) poster="{{ $reel['poster'] }}" @endif src="{{ $reel['video'] }}" data-reel-play aria-label="{{ $reel['title'] }}: the 20-second reel">Your browser can't play this video.</video>
        </div>
        <div class="md-reels-copy">
            <p class="md-eyebrow">THE 20-SECOND REEL · {{ strtoupper($match->league?->name ?? 'FULL TIME') }}</p>
            <h1 id="reel-title">{{ $reel['title'] }}</h1>
            <p class="md-reels-now">Every goal and red card from {{ $match->homeTeam?->name }} v {{ $match->awayTeam?->name }}, told in twenty seconds minutes after the final whistle.</p>
            <div class="md-home-actions">
                <a class="md-primary" href="{{ $reel['link'] }}">Full story and stats</a>
                <a class="md-secondary" href="{{ $reel['video'] }}" download>Save the video</a>
            </div>
            @if($more->isNotEmpty())
                <p class="md-eyebrow">MORE REELS</p>
                <div class="md-reels-rail" role="list" aria-label="More reels">
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
