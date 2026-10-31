@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap">
    <p class="md-eyebrow">THE 20-SECOND REELS</p>
    <h1>Every match, told in twenty seconds.</h1>
    <p class="md-lead">Premier League reels made minutes after each final whistle. Sized for WhatsApp Status, TikTok and Reels.</p>
    @if($reels->isEmpty())
        <div class="md-panel"><h2>No reels yet this round</h2><p>Reels appear here a few minutes after full time.</p><a class="md-primary" href="{{ route('matches.index') }}">See this week's fixtures</a></div>
    @else
        <div class="md-reel-index">
            @foreach($reels as $reel)
                <a href="{{ $reel['page'] }}">
                    @if($reel['poster'])<img src="{{ $reel['poster'] }}" alt="" loading="lazy" width="216" height="384">@else<span class="md-reel-index-empty">20s</span>@endif
                    <b>{{ $reel['title'] }}</b><small>{{ $reel['played']->setTimezone('Africa/Lagos')->format('D j M') }}</small>
                </a>
            @endforeach
        </div>
    @endif
</div></section>
<x-social-follow variant="block" context="reels" />
@endsection
