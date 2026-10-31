{{-- WhatsApp, X and copy-link sharing with UTM-tagged links (App\Support\ShareLink). --}}
@props(['url', 'text' => '', 'campaign' => 'page', 'label' => 'Share'])
@php($links = \App\Support\ShareLink::links($url, $text, $campaign))
<div {{ $attributes->merge(['class' => 'md-share-bar']) }} data-share-campaign="{{ $campaign }}">
    <span class="md-share-label">{{ $label }}</span>
    <a href="{{ $links['whatsapp'] }}" target="_blank" rel="noopener" data-share-network="whatsapp"><x-social-icon network="whatsapp" class="w-4 h-4" /><span>WhatsApp</span></a>
    <a href="{{ $links['x'] }}" target="_blank" rel="noopener" data-share-network="x"><x-social-icon network="x" class="w-4 h-4" /><span>Post on X</span></a>
    <button type="button" class="md-copy-link" data-copy="{{ $links['copy'] }}" data-share-network="copy"><x-social-icon network="link" class="w-4 h-4" /><span>Copy link</span></button>
</div>
