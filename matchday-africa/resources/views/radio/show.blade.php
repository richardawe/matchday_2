@extends('layouts.public')
@section('content')
<div class="md-radio-page md-wrap"><p><a href="{{ route('radio.index') }}">← All editions</a></p>@include('partials.radio-player', ['showDelay' => false])</div>
@endsection
