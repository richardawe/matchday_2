@extends('layouts.public')
@section('content')
@include('partials.breaking', ['variant' => 'strip'])
<div class="md-radio-page md-wrap">@include('partials.radio-player', ['showDelay' => false, 'showTranscript' => true])</div>
@endsection
