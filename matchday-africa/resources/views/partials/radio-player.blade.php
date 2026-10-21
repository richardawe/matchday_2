<section class="md-radio" aria-labelledby="radio-title-{{ $bulletin->id }}" data-radio-player data-radio-unlock="{{ asset('radio/silence.wav') }}" data-share-url="{{ route('radio.show', $bulletin) }}" data-share-title="Matchday Africa · {{ $bulletin->title }}">
 <p class="md-eyebrow">MATCHDAY RADIO</p>
 <div class="md-radio-heading"><h2 id="radio-title-{{ $bulletin->id }}">{{ $bulletin->title }}</h2><span>{{ $bulletin->duration_label }}</span></div>
 <p class="md-radio-time">{{ $bulletin->recorded_at->copy()->timezone('Africa/Lagos')->format('j M · H:i') }} WAT</p>
 @php($slides = app(\App\Services\RadioVisuals::class)->slides($bulletin))
 @if($slides)
 <div class="md-radio-stories" data-radio-stories hidden aria-label="Stories in this bulletin">
 @foreach($slides as $slide)
 <figure class="md-radio-story" data-radio-story data-start="{{ $slide['start'] }}" data-end="{{ $slide['end'] }}" @if(!$loop->first) hidden @endif>
 <img src="{{ $slide['image'] }}" alt="{{ $slide['alt'] }}" loading="lazy" width="960" height="540">
 <figcaption><span>{{ $slide['label'] }} · {{ $loop->iteration }}/{{ count($slides) }}</span><h3>{{ $slide['title'] }}</h3><small>Matchday illustration</small></figcaption>
 </figure>
 @endforeach
 </div>
 @endif
 <audio controls preload="none" aria-label="Listen to {{ $bulletin->title }}" src="{{ route('radio.audio', $bulletin) }}"></audio>
 <div class="md-radio-controls" data-radio-controls hidden>
  <button type="button" class="md-radio-play" data-radio-play aria-label="Listen now" aria-pressed="false"><span data-radio-icon aria-hidden="true">▶</span><span data-radio-label>Listen now</span></button>
  <button type="button" class="md-radio-share" data-radio-share><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 16V3m-5 5 5-5 5 5M5 13v7a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7"/></svg> Share</button>
 </div>
 <div class="md-radio-progress" data-radio-progress hidden><input type="range" min="0" max="{{ $bulletin->duration_seconds }}" value="0" step="1" aria-label="Playback position" data-radio-seek><span data-radio-elapsed>0:00</span></div>
 <p class="md-radio-feedback" data-radio-feedback role="status" aria-live="polite"></p>
 @if(($showDelay ?? true) && $bulletin->is_delayed)<p class="md-radio-delayed">Last available edition. A newer update is delayed.</p>@endif
 @if($showTranscript ?? false)
 <details class="md-radio-transcript"><summary>About this bulletin</summary><p class="md-radio-disclosure">{{ config('radio.disclosure') }}</p>
 @foreach(preg_split('/\n\s*\n/', $bulletin->script) as $paragraph)<p>{{ $paragraph }}</p>@endforeach
 <h3>Sources</h3><ul>
 @php($used = collect($bulletin->segments)->pluck('fact_keys')->flatten()->all())
 @foreach(collect($bulletin->facts)->whereIn('key', $used)->unique('url') as $fact)<li><a href="{{ $fact['url'] }}" rel="nofollow noopener" target="_blank">{{ $fact['source'] }} — {{ $fact['headline'] }}</a></li>@endforeach
 </ul><p>Recorded {{ $bulletin->recorded_at->copy()->timezone('Africa/Lagos')->format('j F Y, H:i') }} WAT. Results and reports may change after recording.</p></details>
 @endif
</section>
