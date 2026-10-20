@extends('layouts.public')
@section('content')
<div class="md-radio-page md-wrap"><header class="md-home-heading"><div><p class="md-eyebrow">MATCHDAY RADIO</p><h1>Your football. Your next four minutes.</h1><p>New editions at 6am, noon and 6pm WAT.</p></div><a href="{{ route('radio.feed') }}">Subscribe by RSS →</a></header>
@if($bulletin)@include('partials.radio-player')@else<p>The next bulletin will appear here.</p>@endif
<section class="md-radio-archive"><h2>Past editions</h2>@forelse($editions as $edition)<a href="{{ route('radio.show', $edition) }}"><strong>{{ $edition->title }}</strong><span>{{ $edition->recorded_at->copy()->timezone('Africa/Lagos')->format('D j M · H:i') }} WAT · {{ $edition->duration_label }}</span></a>@empty<p>No published editions yet.</p>@endforelse</section></div>
@endsection
