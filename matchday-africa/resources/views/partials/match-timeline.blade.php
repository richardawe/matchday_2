@php
    $timeline = app(\App\Services\Griot\GriotTimeline::class);
    $posts = $timeline->posts($match);
@endphp
@if($posts->isNotEmpty())
@php
    $teller = app(\App\Services\Griot\GriotTeller::class);
    $shots = $timeline->shots($match);
    $momentum = $timeline->momentum($shots);
    $nowMinute = $match->isFinished() ? 96 : min(96, max(1, (int) ($posts->max(fn ($p) => $p->period === 1 ? min(46, $p->minute + ($p->extra_minute ?? 0)) : $p->minute + ($p->extra_minute ?? 0))) + 1));
    $club = fn ($team) => trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $team?->name));
    $voice ??= 'en';
@endphp
<section class="md-tl" aria-labelledby="md-tl-title"><div class="md-wrap">
    <header class="md-tl-head">
        <div><p class="md-eyebrow">LIVE · TOLD BY THE GRIOT</p><h2 id="md-tl-title">The Griot's timeline</h2></div>
        <p>{{ $posts->count() }} {{ Str::plural('post', $posts->count()) }} · newest first</p>
    </header>
    <div class="md-tl-grid">
        <ol class="md-tl-feed">
            @foreach($posts as $post)
                @php
                    [$headline, $line] = $teller->tell($post, $voice, $match);
                    $clockLabel = $post->clock ?? (['kickoff' => '0′', 'half_time' => 'HT', 'full_time' => 'FT'][$post->kind] ?? '');
                    $audio = $voice === 'en' ? $post->audioUrl() : null;
                @endphp
                <li class="md-tl-post is-{{ $post->kind }}" id="griot-{{ $post->id }}">
                    <span class="md-tl-clock">{{ $clockLabel }}</span>
                    <div>
                        <span class="md-tl-tag">{{ $teller->tag($post) }}</span>
                        <h3>{{ $headline }}</h3>
                        <p>{{ $line }}</p>
                        @if(in_array($post->kind, ['half_time', 'full_time'], true))
                            <div class="md-tl-stats"><span>Shots<b>{{ $post->facts['home_shots'] ?? 0 }} · {{ $post->facts['away_shots'] ?? 0 }}</b></span><span>On target<b>{{ $post->facts['home_on_target'] ?? 0 }} · {{ $post->facts['away_on_target'] ?? 0 }}</b></span></div>
                        @endif
                        @if($audio)
                            <audio class="md-tl-audio" controls preload="none" src="{{ $audio }}">{{ $headline }}</audio>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
        <aside class="md-tl-side">
            <figure class="md-tl-panel">
                <figcaption><h3>Who's on top</h3><p>Shots, weighted by how close to goal they came.</p></figcaption>
                <svg viewBox="0 0 384 112" role="img" aria-label="Momentum by minute: up for {{ $club($match->homeTeam) }}, down for {{ $club($match->awayTeam) }}">
                    <line x1="0" y1="52" x2="384" y2="52" class="md-tl-axis"/>
                    @foreach([0 => "0′", 45 => "45′", 90 => "90′"] as $m => $label)
                        <line x1="{{ $m * 4 }}" y1="4" x2="{{ $m * 4 }}" y2="100" class="md-tl-grid-line"/><text x="{{ $m * 4 + 3 }}" y="110">{{ $label }}</text>
                    @endforeach
                    @foreach($momentum as $minute => $value)
                        @if($minute < $nowMinute && abs($value) >= 0.02)
                            <rect x="{{ $minute * 4 + 0.4 }}" y="{{ $value > 0 ? 52 - $value * 46 : 52 }}" width="3.2" height="{{ max(0.5, abs($value) * 46) }}" class="{{ $value > 0 ? 'is-home' : 'is-away' }}"/>
                        @endif
                    @endforeach
                </svg>
                <p class="md-tl-legend"><span class="is-home">{{ $club($match->homeTeam) }}</span><span class="is-away">{{ $club($match->awayTeam) }}</span></p>
            </figure>
            @if($shots->whereNotNull('x')->isNotEmpty())
            <figure class="md-tl-panel">
                <figcaption><h3>Every shot</h3><p>Large dot: goal. Ringed: on target or off the post.</p></figcaption>
                <svg viewBox="-1 -1 107 70" role="img" aria-label="Shot map: {{ $club($match->homeTeam) }} attack right, {{ $club($match->awayTeam) }} attack left">
                    <rect x="0" y="0" width="105" height="68" class="md-tl-pitch"/>
                    <g class="md-tl-lines"><line x1="52.5" y1="0" x2="52.5" y2="68"/><circle cx="52.5" cy="34" r="9.15"/><rect x="0" y="13.84" width="16.5" height="40.32"/><rect x="88.5" y="13.84" width="16.5" height="40.32"/><rect x="0" y="24.84" width="5.5" height="18.32"/><rect x="99.5" y="24.84" width="5.5" height="18.32"/></g>
                    @foreach($shots->whereNotNull('x') as $shot)
                        @php
                            $home = $shot->side !== 'away';
                            $cx = round(($home ? $shot->x : 100 - $shot->x) * 1.05, 1);
                            $cy = round(($home ? $shot->y : 100 - $shot->y) * 0.68, 1);
                            $goal = in_array($shot->type, ['goal', 'own_goal', 'penalty_goal'], true);
                            $ringed = in_array($shot->type, ['shot_saved', 'woodwork'], true);
                        @endphp
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $goal ? 2.4 : ($ringed ? 1.5 : 1.1) }}" class="{{ $home ? 'is-home' : 'is-away' }} {{ $goal ? 'is-goal' : ($ringed ? 'is-ringed' : 'is-faint') }}"><title>{{ $shot->player }} · {{ $shot->minute }}{{ $shot->extra_minute ? '+'.$shot->extra_minute : '' }}′</title></circle>
                    @endforeach
                </svg>
                <p class="md-tl-legend"><span class="is-home">{{ $club($match->homeTeam) }} attack →</span><span class="is-away">← {{ $club($match->awayTeam) }} attack</span></p>
            </figure>
            @endif
            <p class="md-tl-note">Told by the Griot from live match data.</p>
        </aside>
    </div>
</div></section>
@endif
