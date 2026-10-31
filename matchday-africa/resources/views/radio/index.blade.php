@extends('layouts.public')
@section('meta_title', 'Matchday Radio: football bulletins three times a day | Matchday Africa')
@section('meta_description', 'Short football bulletins at 6am, noon and 6pm Lagos time: results, fixtures and the stories that matter to African fans.')
@section('content')
<div class="md-radio-page md-wrap">
@if($bulletin)@include('partials.radio-player')@else<p>The next bulletin will appear here.</p>@endif
</div>
@endsection
