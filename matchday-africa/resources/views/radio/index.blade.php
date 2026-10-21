@extends('layouts.public')
@section('content')
<div class="md-radio-page md-wrap">
@if($bulletin)@include('partials.radio-player')@else<p>The next bulletin will appear here.</p>@endif
</div>
@endsection
