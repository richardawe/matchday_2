{{-- Matchday Africa's own accounts (config/social.php). variant: icons (header/footer row) or block ("Follow us"). --}}
@props(['variant' => 'icons', 'title' => 'Follow us for every reel.', 'context' => null, 'size' => 'md'])
@php($accounts = config('social.accounts', []))
@if($variant === 'block')
<section {{ $attributes->merge(['class' => 'md-follow']) }} aria-labelledby="follow-{{ $context ?? 'us' }}">
    <div class="md-wrap md-follow-inner">
        <div>
            <p class="md-eyebrow">FOLLOW US</p>
            <h2 id="follow-{{ $context ?? 'us' }}">{{ $title }}</h2>
            <p>New reels minutes after full time, goal cards as they happen and the morning fixtures.</p>
        </div>
        <ul class="md-follow-list">
            @foreach($accounts as $key => $account)
                <li><a href="{{ $account['url'] }}" target="_blank" rel="noopener me" data-follow="{{ $key }}"><x-social-icon :network="$key" class="w-6 h-6" /><span><b>{{ $account['name'] }}</b><small>{{ $account['handle'] }}</small></span></a></li>
            @endforeach
        </ul>
    </div>
</section>
@else
<div {{ $attributes->merge(['class' => 'md-follow-icons'.($size === 'sm' ? ' is-sm' : '')]) }}>
    @foreach($accounts as $key => $account)
        <a href="{{ $account['url'] }}" target="_blank" rel="noopener me" aria-label="Matchday Africa on {{ $account['name'] }} ({{ $account['handle'] }})" title="{{ $account['handle'] }} on {{ $account['name'] }}" data-follow="{{ $key }}"><x-social-icon :network="$key" :class="$size === 'sm' ? 'w-4 h-4' : 'w-5 h-5'" /></a>
    @endforeach
</div>
@endif
