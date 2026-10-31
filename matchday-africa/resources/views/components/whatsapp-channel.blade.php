{{-- "Join our WhatsApp channel" (WHATSAPP_CHANNEL_URL). Hidden until the link is set. --}}
@props(['context' => 'page'])
@if($channel = config('social.whatsapp_channel'))
<aside {{ $attributes->merge(['class' => 'md-wa-channel']) }} aria-label="Matchday Africa WhatsApp channel">
    <div class="md-wrap md-wa-inner">
        <x-social-icon network="whatsapp" class="w-8 h-8" />
        <div>
            <p class="md-eyebrow">WHATSAPP CHANNEL</p>
            <h2>Goals, reels and results, straight to WhatsApp.</h2>
            <p>Free. Every reel minutes after full time, the morning fixtures and the night's results.</p>
        </div>
        <a class="md-wa-join" href="{{ $channel }}" target="_blank" rel="noopener" data-wa-channel="{{ $context }}">Join our WhatsApp channel</a>
    </div>
</aside>
@endif
