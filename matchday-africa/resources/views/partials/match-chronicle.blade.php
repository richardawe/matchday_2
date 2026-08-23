@php($reportedEvent=$match->events->filter(fn($event)=>data_get($event->metadata,'provider')==='matchday-reporter')->sortByDesc('created_at')->first())
<div class="md-wrap md-narrow">
    <div class="md-chronicle-label"><p class="md-eyebrow">THE LIVE CHRONICLE</p><span class="{{ ($fresh||$reportedEvent) ? 'fresh' : 'stale' }}">{{ $reportedEvent ? 'VERIFIED REPORT' : ($fresh ? 'PROVIDER CURRENT' : 'AWAITING FRESH DATA') }}</span></div>
    <h2>{{ $mythStory['headline'] }}</h2>
    <p>{{ $mythStory['story'] }}</p>
    @if(count($mythStory['beats']))
        <div class="md-beats">@foreach($mythStory['beats'] as $beat)<div data-beat-key="{{ $beat['key'] }}"><b>{{ $beat['minute'] !== null ? $beat['minute'].'′' : 'NOW' }}</b><span>{{ $beat['text'] }}</span></div>@endforeach</div>
    @else
        <div class="md-chronicle-wait"><strong>The chronicle is listening.</strong><span>Confirmed incidents will appear here as the provider reports them.</span></div>
    @endif
    <small class="md-chronicle-freshness">{{ $reportedEvent ? 'Matchday reporter update: '.$reportedEvent->created_at->diffForHumans() : ($match->last_api_update ? 'Provider update: '.$match->last_api_update->diffForHumans() : 'No provider update has been recorded yet.') }}</small>
</div>
