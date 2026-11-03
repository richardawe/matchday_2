<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <!-- Header -->
    <div class="text-center mb-8">
        <p class="matchday-kicker">RETURN TO THE WATCHTOWER</p>
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Welcome back.</h1>
        <p class="text-gray-600">Sign in for scores, predictions and live conversation.</p>
    </div>

    <!-- Social Login Buttons -->
    <div class="mb-8" style="position: relative; z-index: 0;">
        <div class="grid grid-cols-1 gap-4">
            <!-- Google Login -->
            <div id="google-signin-button" 
                 class="w-full flex items-center justify-center px-6 py-3 border border-gray-300 rounded-lg shadow-sm bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors cursor-pointer"
                 style="position: relative; z-index: 0;">
                <svg class="w-5 h-5 mr-3" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                <span class="text-gray-700 font-medium">Continue with Google</span>
            </div>

            <!-- Twitter Login -->
            <div id="twitter-signin-button" 
                 class="w-full flex items-center justify-center px-6 py-3 border border-gray-300 rounded-lg shadow-sm bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-400 transition-colors cursor-pointer"
                 style="position: relative; z-index: 0;">
                <svg class="w-5 h-5 mr-3" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                <span class="text-gray-700 font-medium">Continue with X</span>
            </div>
        </div>
    </div>

    <!-- Divider -->
    <div class="relative mb-8">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-gray-300" />
        </div>
        <div class="relative flex justify-center text-sm">
            <span class="px-4 bg-gray-50 text-gray-500 font-medium">Or sign in with email</span>
        </div>
    </div>

    <!-- Email/Password Form -->
    <div class="bg-gray-50 rounded-lg p-6" style="position: relative; z-index: 1;">
        <form method="POST" action="{{ route('login') }}" style="position: relative; z-index: 1;">
            @csrf

            <!-- Email Address -->
            <div class="mb-4">
                <x-input-label for="email" :value="__('Email Address')" class="text-sm font-medium text-gray-700" />
                <input id="email" 
                    class="block mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 cursor-text" 
                    type="email" 
                    name="email" 
                    value="{{ old('email') }}" 
                    required 
                    autofocus 
                    autocomplete="username" 
                    placeholder="Enter your email address"
                    style="pointer-events: auto; position: relative; z-index: 1;" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <!-- Password -->
            <div class="mb-4">
                <x-input-label for="password" :value="__('Password')" class="text-sm font-medium text-gray-700" />
                <input id="password" 
                    class="block mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 cursor-text"
                    type="password"
                    name="password"
                    required 
                    autocomplete="current-password" 
                    placeholder="Enter your password"
                    style="pointer-events: auto; position: relative; z-index: 1;" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="flex items-center justify-between mb-6">
                <label for="remember_me" class="inline-flex items-center">
                    <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500" name="remember">
                    <span class="ml-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
                </label>
                
                @if (Route::has('password.request'))
                    <a class="text-sm text-blue-600 hover:text-blue-500 font-medium" href="{{ route('password.request') }}">
                        {{ __('Forgot password?') }}
                    </a>
                @endif
            </div>

            <!-- Submit Button -->
            <button type="submit" 
                id="login-submit-button"
                class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                style="pointer-events: auto; position: relative; z-index: 1; cursor: pointer;">
                {{ __('Sign In') }}
            </button>
        </form>
    </div>

    <!-- Sign Up Link -->
    <div class="mt-6 text-center" style="position: relative; z-index: 1;">
        <p class="text-sm text-gray-600">
            Don't have an account? 
            <a href="{{ route('register') }}" 
               id="signup-link"
               class="font-medium text-blue-600 hover:text-blue-500 underline"
               style="pointer-events: auto; position: relative; z-index: 1; cursor: pointer; display: inline-block; padding: 2px 4px;"
               onclick="console.log('Signup link clicked via onclick');">
                Sign up for free
            </a>
        </p>
    </div>
</x-guest-layout>
