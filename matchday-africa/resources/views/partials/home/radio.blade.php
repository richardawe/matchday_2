@if(\App\Models\RadioBulletin::available())
<div class="md-radio-home" id="matchday-radio"><div class="md-wrap">
@php($bulletin = \App\Models\RadioBulletin::latestPlayable())
@if($bulletin)@include('partials.radio-player', ['bulletin' => $bulletin])
@else<section class="md-radio"><p class="md-eyebrow">MATCHDAY RADIO</p><h2>Your football. Your next four minutes.</h2><p>The next bulletin will appear here.</p><p>New editions at 6am, noon and 6pm WAT.</p></section>@endif
</div></div>
@endif
