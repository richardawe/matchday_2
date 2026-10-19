@php
    $griot = app(\App\Services\Griot::class);
    $told = $chapters->reverse()->values();
@endphp
@if($told->isNotEmpty())
<section class="md-story"><div class="md-wrap">
    <header class="md-story-head">
        <div><p class="md-eyebrow">THE STORY SO FAR · TOLD BY THE GRIOT</p><h2>{{ $told->count() }} {{ Str::plural('chapter', $told->count()) }}</h2></div>
        <nav class="md-story-voices" aria-label="Griot's voice">
            @foreach(\App\Services\Griot::VOICES as $key => $label)
                <a href="{{ route('matches.show', ['match' => $match, 'voice' => $key]) }}#match-story" @class(['is-active' => $voice === $key]) @if($voice === $key) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </header>
    @if(!empty($reel))
        @php
            $final = $chapters->firstWhere('type', 'full_time');
        @endphp
        <div class="md-reel">
            <video controls playsinline preload="metadata" @if($final) poster="{{ $final->cardUrl($voice, 'story') }}" @endif src="{{ $reel }}">Your browser can't play this video.</video>
            <div>
                <p class="md-eyebrow">THE 20-SECOND REEL</p>
                <h3>The whole match, told in twenty seconds.</h3>
                <p>Every goal and red card with a talking-drum beat, made minutes after the final whistle. Sized for WhatsApp Status.</p>
                <div class="md-story-share">
                    <a href="https://wa.me/?text={{ rawurlencode('Watch the 20-second story of '.$match->homeTeam?->name.' v '.$match->awayTeam?->name.' '.route('matches.show', $match)) }}" target="_blank" rel="noopener">Share on WhatsApp</a>
                    <a href="{{ $reel }}" download>Save the video</a>
                </div>
            </div>
        </div>
    @endif
    <div class="md-story-strip">
        @foreach($told as $chapter)
            @php
                [$headline, $line] = $griot->tell($chapter, $voice);
                $link = route('matches.show', ['match' => $match, 'moment' => $chapter->id]);
                $shareText = "{$headline} {$line}";
            @endphp
            <figure class="md-story-card">
                <img src="{{ $chapter->cardUrl($voice, 'feed') }}" alt="{{ $headline }}. {{ $line }}" width="1080" height="1350" loading="{{ $loop->index < 2 ? 'eager' : 'lazy' }}">
                <figcaption>
                    <span class="sr-only">{{ $headline }}. {{ $line }}</span>
                    <div class="md-story-share">
                        <a href="https://wa.me/?text={{ rawurlencode($shareText.' '.$link) }}" target="_blank" rel="noopener">WhatsApp</a>
                        <a href="https://x.com/intent/post?text={{ rawurlencode($shareText) }}&amp;url={{ rawurlencode($link) }}" target="_blank" rel="noopener">X</a>
                        <a href="{{ $chapter->cardUrl($voice, 'story') }}" target="_blank" rel="noopener">Status size</a>
                    </div>
                </figcaption>
            </figure>
        @endforeach
    </div>
</div></section>
@endif
