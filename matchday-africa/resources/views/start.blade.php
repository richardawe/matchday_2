@extends('layouts.public')
@section('meta_title', 'Start here: African players, reels and Beat the Griot | Matchday Africa')
@section('meta_description', 'African footballers and players of African descent, Premier League first. Call the score, take on the Griot and follow today\'s African players. Free.')
@section('content')
@php($carry = fn (string $url, ?string $campaign = null) => \App\Services\StartPage::carry($url, $tags, $campaign))
<section class="md-start" aria-labelledby="start-title">
    <div class="md-wrap md-start-hero">
        <p class="md-start-kicker">Matchday Africa</p>
        <h1 id="start-title">African footballers, every matchday.</h1>
        <p class="md-start-intro">African players and players of African descent, with the Premier League first. Scores, 20-second reels and your call on the next match.</p>
        <div class="md-start-action" id="start-action">
            <p>{{ $primary['line'] }}</p>
            @if($primary['kind'] !== 'alerts')
                <a class="md-primary md-start-go" href="{{ $primary['href'] }}" data-track="start_{{ $primary['kind'] }}">{{ $primary['label'] }}</a>
            @elseif(\App\Models\EmailSubscriber::ready())
                @if(session('subscribed'))
                    <p class="md-start-done" role="status">{{ session('subscribed') }}</p>
                @else
                    <form method="POST" action="{{ route('subscribe') }}" class="md-start-alerts" data-track="start_alerts">
                        @csrf
                        <input type="hidden" name="source" value="/start">
                        <label class="sr-only" for="start-email">Email address</label>
                        <input id="start-email" type="email" name="email" required maxlength="255" autocomplete="email" placeholder="Your email" value="{{ old('email') }}">
                        <input type="text" name="website" tabindex="-1" autocomplete="off" class="md-hp" aria-hidden="true">
                        <button type="submit" class="md-primary md-start-go">{{ $primary['label'] }}</button>
                        @error('email')<span class="md-start-error" role="alert">{{ $message }}</span>@enderror
                    </form>
                    @guest<p class="md-start-small">Or <a href="#start-account">create a free account</a> to save picks and turn on alerts in the app.</p>@endguest
                @endif
            @else
                <a class="md-primary md-start-go" href="{{ auth()->check() ? route('notifications.index') : '#start-account' }}">{{ $primary['label'] }}</a>
            @endif
        </div>
    </div>
</section>

<section class="md-start-section" aria-labelledby="start-players">
    <div class="md-wrap">
        @if($players->isNotEmpty())
            <h2 id="start-players">Today's African players</h2>
            <ul class="md-start-players">
                @foreach($players as $player)
                    @php($fixture = $player->focus_match)
                    @php($number = \App\Services\AfricanFocus::headline((array) $player->focus_stats))
                    <li>
                        <a href="{{ $carry(route('discovery.show', $player, false), 'player') }}">
                            @if($player->photo_url)<img src="{{ $player->photo_url }}" alt="" width="56" height="56" loading="lazy">@else<span class="md-start-initial" aria-hidden="true">{{ Str::upper(Str::substr($player->name, 0, 1)) }}</span>@endif
                            <span class="md-start-who">
                                <b>{{ $player->name }}</b>
                                <small>{{ \App\Support\AfricanScope::label($player) }}@if($player->team) · {{ $player->team->display_name }}@endif</small>
                                @if($fixture)<small>{{ \App\Support\ClubName::short($fixture->homeTeam) }} @if($fixture->home_score !== null){{ $fixture->home_score }}–{{ $fixture->away_score }}@else v @endif {{ \App\Support\ClubName::short($fixture->awayTeam) }}</small>@endif
                            </span>
                            @if($number)<em>{{ $number }}</em>@endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @elseif($upcoming->isNotEmpty())
            <h2 id="start-players">Coming up with African players</h2>
            <ul class="md-start-fixtures">
                @foreach($upcoming as $match)
                    <li>
                        <a href="{{ $carry(route('matches.show', $match, false).'#prediction-arena', 'predictions') }}">
                            <small>{{ $match->league?->name }} · {{ $match->kickoffLabel() }}</small>
                            <b>{{ \App\Support\ClubName::short($match->homeTeam) }} v {{ \App\Support\ClubName::short($match->awayTeam) }}</b>
                            @if($match->african_players->isNotEmpty())<small>With {{ $match->african_players->join(', ', ' and ') }}</small>@endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <h2 id="start-players">African players</h2>
        @endif
        <p class="md-start-more"><a href="{{ $carry(route('discovery.roster', [], false), 'roster') }}">Every African player in the Premier League this week</a></p>
    </div>
</section>

@if($radio || $slideshows->isNotEmpty() || $reels->isNotEmpty())
<section class="md-start-section md-start-quiet" aria-labelledby="start-watch">
    <div class="md-wrap">
        <h2 id="start-watch">Listen and watch</h2>
        @if($radio)
            <a class="md-start-radio" href="{{ $carry(route('radio.show', $radio, false), 'radio') }}">
                <b>Matchday Radio</b>
                <small>Latest bulletin · {{ $radio->duration_label }}</small>
            </a>
        @endif
        @if($slideshows->isNotEmpty() || $reels->isNotEmpty())
            <div class="md-reels-rail" role="list" aria-label="Slideshows and reels">
                @foreach($slideshows as $show)
                    <a role="listitem" class="md-reels-thumb" href="{{ $carry($show->pageUrl(), 'slideshow') }}">
                        @if($show->posterUrl())<img src="{{ $show->posterUrl() }}" alt="" loading="lazy" width="108" height="192">@endif
                        <span>{{ data_get($show->facts, 'title', $show->title()) }}</span>
                    </a>
                @endforeach
                @foreach($reels as $reel)
                    <a role="listitem" class="md-reels-thumb" href="{{ $carry($reel['page'], 'reels') }}">
                        @if($reel['poster'])<img src="{{ $reel['poster'] }}" alt="" loading="lazy" width="108" height="192">@endif
                        <span>{{ $reel['title'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>
@endif

@guest
<section class="md-start-section md-start-quiet" id="start-account" aria-labelledby="start-account-title">
    <div class="md-wrap md-start-narrow">
        <h2 id="start-account-title">Save your picks with a free account</h2>
        @include('partials.signup-options', ['return' => $return, 'utmTags' => $tags])
    </div>
</section>
@endguest
@endsection
