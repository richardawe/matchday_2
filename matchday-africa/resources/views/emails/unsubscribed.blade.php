@extends('layouts.public')
@section('meta_title', 'Unsubscribed | Matchday Africa')
@section('meta_robots', 'noindex, nofollow')
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow">
    <p class="md-eyebrow">EMAIL UPDATES</p>
    <h1>You're unsubscribed.</h1>
    <p class="md-lead">{{ $email }} won't get the weekend fixtures and results email any more. Changed your mind? Sign up again from the footer.</p>
    <a class="md-primary" href="{{ route('home') }}">Back to Matchday Africa</a>
</div></section>
@endsection
