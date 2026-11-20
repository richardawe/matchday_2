{{-- "New on Matchday" (FrontPage::fresh): the newest ten items of every kind, newest first. The pulse
     sends it again (fresh_html) when its key changes. Left out when empty. --}}
<section class="md-front-rail" id="home-fresh" data-fresh-key="{{ $freshKey }}" aria-labelledby="home-fresh-title"@if($fresh->isEmpty()) hidden @endif>
    @if($fresh->isNotEmpty())
    <div class="md-wrap">
        <h2 id="home-fresh-title">New on Matchday</h2>
        <ol class="md-front-rail-list">
            @foreach($fresh as $item)
                <li class="md-front-item md-front-item--{{ $item['kind'] }}" data-fresh-item="{{ $item['key'] }}">
                    @if($item['url'])<a class="md-front-link" href="{{ $item['url'] }}">@else<div>@endif
                        @if($item['card'] ?? false)<span class="md-front-thumb is-fallback" data-fallback-card>{{ $item['label'] }}</span>@else<span class="md-front-thumb{{ $item['portrait'] && $item['image'] ? ' is-portrait' : '' }}">@if($item['image'])<img src="{{ $item['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">@endif</span>@endif
                        <span class="md-front-item-meta"><span class="md-front-kind">{{ $item['label'] }}</span>@if($item['new'])<span class="md-front-new">New</span>@endif</span>
                        <b>{{ Str::limit($item['title'], 90) }}</b>
                        <time datetime="{{ $item['at']->copy()->utc()->toIso8601ZuluString() }}">{{ $item['at']->diffForHumans(['short' => true]) }}</time>
                    @if($item['url'])</a>@else</div>@endif
                </li>
            @endforeach
        </ol>
    </div>
    @endif
</section>
