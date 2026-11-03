@extends('layouts.public')
@section('meta_title', 'The Arena: this week\'s Premier League armies in 3D | Matchday Africa')
@section('meta_description', 'Watch this week\'s Premier League fixtures as armies on the field in the Matchday War arena, then pick your side and challenge a friend.')
@section('meta_image', asset('images/war-share.jpg').'?v=20261103')

{{-- The 3D arena, moved here from the homepage when the reels-first homepage is on. --}}
@section('content')
@if($arena)
    @include('partials.home-arena')
@else
    <section class="md-board"><div class="md-wrap"><div class="md-home-heading"><div><p class="md-eyebrow">THE ARENA</p><h1>No Premier League army is on the field right now.</h1></div><a href="{{ route('matches.index') }}">This week's fixtures →</a></div></div></section>
@endif
<section class="md-play"><div class="md-wrap"><div class="md-home-actions"><a class="md-primary" href="{{ route('war.index') }}">Enter Matchday War</a><a class="md-secondary" href="{{ route('home') }}">Back to Matchday</a></div></div></section>
@endsection
