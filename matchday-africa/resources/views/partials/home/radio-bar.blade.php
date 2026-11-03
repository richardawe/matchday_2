{{-- Matchday Radio as a slim bar at the top of the homepage: the latest bulletin plays in place
     (resources/js/radio.js), the full player and transcript are on /listen. --}}
@if(\App\Models\RadioBulletin::available())
@php($bulletin = \App\Models\RadioBulletin::latestPlayable())
<div class="md-radio-bar" id="matchday-radio" @if($bulletin) data-radio-player data-share-url="{{ route('radio.show', $bulletin) }}" data-share-title="Matchday Africa · {{ $bulletin->title }}" @endif>
    <div class="md-wrap md-radio-bar-inner">
        <span class="md-radio-bar-tag"><i aria-hidden="true"></i>RADIO</span>
        @if($bulletin)
            <a class="md-radio-bar-title" href="{{ route('radio.show', $bulletin) }}"><b>{{ $bulletin->title }}</b><small>{{ $bulletin->recorded_at->copy()->timezone('Africa/Lagos')->format('j M · H:i') }} WAT · {{ $bulletin->duration_label }}</small></a>
            <audio preload="none" src="{{ $bulletin->audioUrl() }}" aria-label="Listen to {{ $bulletin->title }}" controls></audio>
            <div class="md-radio-bar-controls" data-radio-controls hidden>
                <button type="button" class="md-radio-bar-play" data-radio-play aria-label="Listen now" aria-pressed="false"><span data-radio-icon aria-hidden="true">▶</span><span data-radio-label>Listen now</span></button>
                <button type="button" class="md-radio-bar-share" data-radio-share aria-label="Share this bulletin"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 16V3m-5 5 5-5 5 5M5 13v7a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7"/></svg></button>
            </div>
            <div class="md-radio-bar-progress" data-radio-progress hidden><input type="range" min="0" max="{{ $bulletin->duration_seconds }}" value="0" step="1" aria-label="Playback position" data-radio-seek><span data-radio-elapsed>0:00</span></div>
            <p class="md-radio-bar-feedback" data-radio-feedback role="status" aria-live="polite"></p>
        @else
            <a class="md-radio-bar-title" href="{{ route('radio.index') }}"><b>Matchday Radio</b><small>New editions at 6am, noon and 6pm WAT</small></a>
        @endif
    </div>
</div>
@endif
