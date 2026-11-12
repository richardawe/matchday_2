{{-- Approved colour slideshows ($slideshows), labelled apart from the 20-second Griot reels. --}}
@if(($slideshows ?? collect())->isNotEmpty())
<section class="md-slideshow-strip" aria-label="Slideshows">
    <p class="md-eyebrow">{{ $heading ?? 'SLIDESHOWS' }}</p>
    <div class="md-reels-rail" role="list">
        @foreach($slideshows as $show)
            <a role="listitem" class="md-reels-thumb" href="{{ $show->pageUrl() }}">
                @if($show->posterUrl())<img src="{{ $show->posterUrl() }}" alt="" loading="lazy" width="108" height="192">@endif
                <span><small>Slideshow · {{ $show->label() }}</small><br>{{ data_get($show->facts, 'title', $show->title()) }}</span>
            </a>
        @endforeach
    </div>
</section>
@endif
