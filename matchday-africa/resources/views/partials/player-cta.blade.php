{{-- Call the next match (or Beat the Griot), then a guest signup to save picks. No account needed to read or make a first call. --}}
<section class="md-panel md-player-cta" aria-label="Make your call">
    <p class="md-eyebrow">YOUR CALL</p>
    @if($cta)
        <h2>{{ $cta['label'] }}</h2>
        <p>No account needed to make your call.</p>
        <a class="md-primary" href="{{ $cta['href'] }}">{{ str_starts_with($cta['label'], 'Beat') ? 'Play Beat the Griot' : 'Call the score' }}</a>
    @else
        <h2>Call the next match</h2>
        <p>No account needed to make your call.</p>
        <a class="md-primary" href="{{ route('matches.index') }}">See the fixtures</a>
    @endif
    @guest
        <p class="md-reels-now">Sign up to save your picks and get match alerts.</p>
        @include('partials.signup-options', ['return' => $cta['href'] ?? request()->getRequestUri()])
    @endguest
</section>
