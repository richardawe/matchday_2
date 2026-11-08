{{-- The homepage reels: one row, newest match first, the last seven days. The reel on screen plays
     muted and hands over to the next when it ends; tapping a reel opens its match. Before any reels,
     this week's fixture cards fill the row. Swapped in place by the pulse refresh when a reel arrives. --}}
@php($cards = $reels->isEmpty() ? $board->take(6) : collect())
@if($reels->isEmpty() && $cards->isEmpty())
{{-- Nothing yet: an empty, hidden slot the pulse refresh fills when the first reel arrives. --}}
<section class="md-reels md-reels-strip" id="reels-hero" data-reels-key="{{ \App\Services\HomeShowcase::rollingKey($reels) }}" hidden></section>
@else
<section class="md-reels md-reels-strip" id="reels-hero" aria-label="{{ $reels->isNotEmpty() ? 'Match reels from the last seven days' : 'This week\'s fixtures' }}" data-reels-key="{{ \App\Services\HomeShowcase::rollingKey($reels) }}">
    <div class="md-wrap">
        <div class="md-reels-bar">
            <p class="md-eyebrow">{{ $reels->isNotEmpty() ? 'REELS' : 'THIS WEEK' }}</p>
            <div class="md-reels-tools">
                @if($reels->isNotEmpty())<button type="button" class="md-reels-sound" data-reels-sound aria-pressed="false">Sound on</button>@endif
                @if(($reels->isNotEmpty() ? $reels : $cards)->count() > 1)
                    <button type="button" class="md-reels-nav is-prev" data-reels-prev aria-label="Previous">‹</button>
                    <button type="button" class="md-reels-nav is-next" data-reels-next aria-label="Next">›</button>
                @endif
            </div>
        </div>
        <div class="md-reels-row" data-reels-track tabindex="0">
            @foreach($reels as $i => $reel)
                <a class="md-reel-card" data-reel-slide href="{{ route('matches.show', $reel['match']) }}" aria-label="{{ $reel['title'] }}: open the match">
                    <video muted playsinline preload="{{ $i < 2 ? 'metadata' : 'none' }}" @if($reel['poster']) poster="{{ $reel['poster'] }}" @endif src="{{ $reel['video'] }}"></video>
                    <span class="md-reel-card-title"><b>{{ $reel['title'] }}</b><small>{{ $reel['played']->copy()->setTimezone('Africa/Lagos')->format('D j M') }}</small></span>
                </a>
            @endforeach
            @foreach($cards as $slide)
                <a class="md-reel-card is-fixture" data-reel-slide href="{{ route('matches.show', $slide['match']) }}" aria-label="{{ $slide['title'] }}, kick-off {{ $slide['kickoff']->format('D H:i') }} WAT">
                    <img src="{{ $slide['card'] }}" alt="" loading="lazy" width="1200" height="675">
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
