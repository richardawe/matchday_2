<section class="md-radio" aria-labelledby="radio-title-{{ $bulletin->id }}" data-radio-player>
 <div class="md-radio-head"><div><p class="md-eyebrow">MATCHDAY RADIO · THREE DAILY BULLETINS</p><h2 id="radio-title-{{ $bulletin->id }}">{{ $bulletin->title }}</h2><p>African football, the big headlines and the next fixtures.</p></div><a class="md-secondary" href="{{ route('radio.index') }}">Past editions &amp; RSS →</a></div>
 <audio controls preload="none" aria-label="Listen to {{ $bulletin->title }}" src="{{ route('radio.audio', $bulletin) }}"></audio>
 <div class="md-radio-meta"><span>{{ $bulletin->recorded_at->copy()->timezone('Africa/Lagos')->format('j M · H:i') }} WAT · {{ $bulletin->duration_label }} · {{ number_format($bulletin->audio_bytes / 1000000, 2) }} MB</span><label>Speed <select data-radio-speed aria-label="Playback speed"><option value="1">1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option></select></label><a href="{{ route('radio.audio', ['bulletin' => $bulletin, 'download' => 1]) }}">Download MP3</a></div>
 @if(($showDelay ?? true) && $bulletin->is_delayed)<p class="md-radio-delayed">Last available edition. A newer update is delayed.</p>@endif
 <details class="md-radio-transcript"><summary>Transcript &amp; sources</summary><p class="md-radio-disclosure">{{ config('radio.disclosure') }}</p>
 @foreach(preg_split('/\n\s*\n/', $bulletin->script) as $paragraph)<p>{{ $paragraph }}</p>@endforeach
 <h3>Sources</h3><ul>
 @php($used = collect($bulletin->segments)->pluck('fact_keys')->flatten()->all())
 @foreach(collect($bulletin->facts)->whereIn('key', $used)->unique('url') as $fact)<li><a href="{{ $fact['url'] }}" rel="nofollow noopener" target="_blank">{{ $fact['source'] }} — {{ $fact['headline'] }}</a></li>@endforeach
 </ul><p>Recorded {{ $bulletin->recorded_at->copy()->timezone('Africa/Lagos')->format('j F Y, H:i') }} WAT. Results and reports may change after recording.</p></details>
</section>
