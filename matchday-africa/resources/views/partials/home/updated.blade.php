{{-- "Updated HH:MM" from the newest thing on the page (FrontPage::updatedAt); the pulse sends it again. --}}
<p class="md-front-updated" id="home-updated">@if($updatedAt)Updated <time datetime="{{ $updatedAt->copy()->utc()->toIso8601ZuluString() }}">{{ $updatedAt->copy()->timezone('Africa/Lagos')->format('H:i') }}</time> WAT @endif</p>
