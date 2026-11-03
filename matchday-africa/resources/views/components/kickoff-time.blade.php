{{-- A kick-off time for tight score boxes: the WAT time with the zone in small type beneath it. --}}
@props(['match'])
@php($kickoff = $match->kickoffWat())
@if($kickoff){{ $kickoff->format('H:i') }}<small class="md-tz">WAT</small>@else TBC @endif
