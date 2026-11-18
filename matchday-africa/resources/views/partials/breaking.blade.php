{{-- Breaking news (App\Models\BreakingNews::current()): a banner at the top of the homepage, a slim strip
     on match and listen pages. Design-language colours (black, #FFD23F); no sirens, no flashing. --}}
@php($item = $item ?? \App\Models\BreakingNews::current())
@php($variant = $variant ?? 'banner')
@if($item)
@php($kind = $item->linkKind())
@php($external = in_array($kind, ['source', 'video'], true) && parse_url((string) $item->link_url, PHP_URL_HOST) !== parse_url((string) config('app.url'), PHP_URL_HOST))
@php($linkAttrs = $external ? 'target="_blank" rel="noopener nofollow"' : '')
<aside class="md-breaking md-breaking--{{ $variant }}" aria-label="Breaking news" data-breaking-id="{{ $item->id }}">
    <div class="md-wrap md-breaking-inner">
        @if($variant === 'strip')
            <span class="md-breaking-tag">Breaking</span>
            @if($item->link_url)
                <a class="md-breaking-headline" href="{{ $item->link_url }}" {!! $linkAttrs !!}>{{ $item->headline }}</a>
                <a class="md-breaking-link" href="{{ $item->link_url }}" {!! $linkAttrs !!} aria-hidden="true" tabindex="-1">{{ $item->linkLabel() }}</a>
            @else
                <span class="md-breaking-headline">{{ $item->headline }}</span>
            @endif
        @else
            <div class="md-breaking-copy">
                <span class="md-breaking-tag">Breaking</span>
                <p class="md-breaking-headline">@if($item->link_url)<a class="md-breaking-headline-link" href="{{ $item->link_url }}" {!! $linkAttrs !!}>{{ $item->headline }}</a>@else{{ $item->headline }}@endif</p>
                @if($item->body)<p class="md-breaking-body">{{ $item->body }}</p>@endif
                @if($item->link_url)<a class="md-breaking-link" href="{{ $item->link_url }}" {!! $linkAttrs !!}>{{ $item->linkLabel() }}</a>@endif
            </div>
            @if($item->image_url)
                <figure class="md-breaking-image">
                    <img src="{{ $item->image_url }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" width="160" height="120" onerror="this.closest('figure').hidden=true">
                    <figcaption>{{ str_starts_with((string) $item->image_credit, 'Photo') ? $item->image_credit : 'Photo: '.$item->image_credit }}</figcaption>
                </figure>
            @endif
        @endif
    </div>
</aside>
@endif
