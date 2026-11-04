{{-- Full-time banter cards for WhatsApp status (BANTER_CARDS): pick a side and a voice, then send or save. --}}
@if(\App\Services\BanterCardRenderer::ready($match))
@php($banter = app(\App\Services\BanterCardRenderer::class))
@php($sides = collect(['home' => $match->homeTeam, 'away' => $match->awayTeam]))
@php($club = fn ($t) => \App\Support\ClubName::full($t))
@php($cards = [])
@foreach($sides as $side => $team)@foreach(array_keys(\App\Services\Griot::VOICES) as $v)
    @php($w = $banter->words($match, $side, $v))
    @php($cards[$side][$v] = ['img' => \App\Services\BanterCardRenderer::cardUrl($match, $side, $v), 'text' => $w['head'].' '.$w['line']])
@endforeach @endforeach
@php($link = \App\Support\ShareLink::tagged(route('matches.show', $match), 'whatsapp', 'banter'))
<section class="md-banter" id="banter" data-banter data-banter-cards='@json($cards)' data-banter-link="{{ $link }}" data-banter-file="matchday-{{ $match->id }}">
    <div class="md-wrap md-banter-grid">
        <figure class="md-banter-preview"><img data-banter-img src="{{ $cards[\App\Services\BanterCardRenderer::outcome($match, 'home') === 'lost' ? 'home' : 'away']['pcm']['img'] }}" alt="Full-time banter card" width="540" height="960" loading="lazy"></figure>
        <div class="md-banter-controls">
            <p class="md-eyebrow">FULL TIME · FOR YOUR STATUS</p>
            <h2>Send the banter</h2>
            @php($loserSide = \App\Services\BanterCardRenderer::outcome($match, 'home') === 'lost' ? 'home' : 'away')
            <div class="md-banter-choice" role="group" aria-label="Which side">
                @foreach($sides as $side => $team)
                    @php($outcome = \App\Services\BanterCardRenderer::outcome($match, $side))
                    <button type="button" data-banter-side="{{ $side }}" aria-pressed="{{ $side === $loserSide ? 'true' : 'false' }}">{{ $outcome === 'lost' ? 'Roast '.$club($team) : ($outcome === 'won' ? 'Big up '.$club($team) : $club($team)) }}</button>
                @endforeach
            </div>
            <div class="md-banter-choice" role="group" aria-label="Language">
                @foreach(\App\Services\Griot::VOICES as $code => $label)<button type="button" data-banter-voice="{{ $code }}" aria-pressed="{{ $code === 'pcm' ? 'true' : 'false' }}">{{ $label }}</button>@endforeach
            </div>
            <a class="md-banter-wa" data-banter-send href="https://wa.me/?text={{ rawurlencode($cards[$loserSide]['pcm']['text'].' '.$link) }}" target="_blank" rel="noopener">Send to WhatsApp</a>
            <a class="md-secondary" data-banter-save href="{{ $cards[$loserSide]['pcm']['img'] }}" download="matchday-{{ $match->id }}.png">Save image for status</a>
            <p class="md-banter-note">On a phone, "Send to WhatsApp" attaches the card itself. On a computer it opens WhatsApp with the words and the link.</p>
        </div>
    </div>
</section>
@endif
