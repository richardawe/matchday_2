{{-- One-tap signup: Google, or a short email form. $return is where they land afterwards. --}}
@php($return = $return ?? request()->getRequestUri())
<div class="md-signup-options">
    @if(config('services.google.client_id'))
        <a class="md-google" href="{{ route('auth.google.start', ['return' => $return]) }}" data-track="signup_google">
            <svg class="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
            Continue with Google
        </a>
        <p class="md-signup-or">or with email</p>
    @endif
    <form method="POST" action="{{ route('register') }}" class="md-signup-email" data-track="signup_email">
        @csrf
        @if($errors->any())<p class="md-signup-error" role="alert">{{ $errors->first() }}</p>@endif
        <input type="hidden" name="return" value="{{ $return }}">
        <label><span class="sr-only">Your name</span><input name="name" autocomplete="name" placeholder="Your name" required maxlength="255"></label>
        <label><span class="sr-only">Email</span><input name="email" type="email" autocomplete="email" placeholder="Email" required maxlength="255"></label>
        <label><span class="sr-only">Password</span><input name="password" type="password" autocomplete="new-password" placeholder="Password (8+ characters)" required minlength="8"></label>
        <button class="md-primary">Create my free account</button>
    </form>
    <p class="md-signup-login">Already in? <a href="{{ route('login') }}">Log in</a> and your pick comes with you.</p>
</div>
