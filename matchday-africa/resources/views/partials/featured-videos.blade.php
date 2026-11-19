{{-- "As seen on TikTok" ($featured from SocialVideos::row()): featured long videos, pinned and recent
     slideshows and radio videos. Each card opens a page on this site. Left out when empty. --}}
@if(($featured ?? collect())->isNotEmpty())
<section class="md-featured-row" aria-labelledby="featured-videos-title">
    <h2 id="featured-videos-title">As seen on TikTok</h2>
    <p class="md-featured-row-lead">Our longer videos: documentaries, compilations, radio round-ups and slideshows.</p>
    <div class="md-reels-rail" role="list">
        @foreach($featured as $card)
            <a role="listitem" class="md-reels-thumb" href="{{ $card['page'] }}" data-track="featured_{{ $card['kind'] }}">
                @if($card['poster'])<img src="{{ $card['poster'] }}" alt="" loading="lazy" width="152" height="270">@else<span class="md-reel-index-empty" aria-hidden="true">{{ $card['length'] ?? 'Video' }}</span>@endif
                <span><small>{{ $card['label'] }}@if($card['length'] && $card['poster']) · {{ $card['length'] }}@endif</small><br>{{ $card['title'] }}</span>
            </a>
        @endforeach
    </div>
</section>
@endif
