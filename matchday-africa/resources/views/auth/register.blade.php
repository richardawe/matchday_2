<x-guest-layout>
    <!-- Header -->
    <div class="text-center mb-8">
        <p class="matchday-kicker">JOIN THE GRANDSTAND</p>
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Take your place.</h1>
        <p class="text-gray-600">Create an account for scores, predictions and live conversation.</p>
    </div>

    <!-- Social Registration Buttons -->
    <div class="mb-8" style="position: relative; z-index: 0;">
        <div class="grid grid-cols-1 gap-4">
            <!-- Google Registration -->
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

            <!-- Twitter Registration -->
            <div id="twitter-signin-button" 
                 class="w-full flex items-center justify-center px-6 py-3 border border-gray-300 rounded-lg shadow-sm bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-400 transition-colors cursor-pointer"
                 style="position: relative; z-index: 0;">
                <svg class="w-5 h-5 mr-3" fill="#1DA1F2" viewBox="0 0 24 24">
                    <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                </svg>
                <span class="text-gray-700 font-medium">Continue with Twitter</span>
            </div>
        </div>
    </div>

    <!-- Divider -->
    <div class="relative mb-8">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-gray-300" />
        </div>
        <div class="relative flex justify-center text-sm">
            <span class="px-4 bg-gray-50 text-gray-500 font-medium">Or register with email</span>
        </div>
    </div>

    <!-- Email/Password Form -->
    <div class="bg-gray-50 rounded-lg p-6" style="position: relative; z-index: 1;">
        <form method="POST" action="{{ route('register') }}" style="position: relative; z-index: 1;">
            @csrf

            <!-- Name -->
            <div class="mb-4">
                <x-input-label for="name" :value="__('Full Name')" class="text-sm font-medium text-gray-700" />
                <input id="name" 
                    class="block mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 cursor-text" 
                    type="text" 
                    name="name" 
                    value="{{ old('name') }}" 
                    required 
                    autofocus 
                    autocomplete="name" 
                    placeholder="Enter your full name"
                    style="pointer-events: auto; position: relative; z-index: 1;" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <!-- Email Address -->
            <div class="mb-4">
                <x-input-label for="email" :value="__('Email Address')" class="text-sm font-medium text-gray-700" />
                <input id="email" 
                    class="block mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 cursor-text" 
                    type="email" 
                    name="email" 
                    value="{{ old('email') }}" 
                    required 
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
                    autocomplete="new-password" 
                    placeholder="Create a password"
                    style="pointer-events: auto; position: relative; z-index: 1;" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- Confirm Password -->
            <div class="mb-6">
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" class="text-sm font-medium text-gray-700" />
                <input id="password_confirmation" 
                    class="block mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 cursor-text"
                    type="password"
                    name="password_confirmation" 
                    required 
                    autocomplete="new-password" 
                    placeholder="Confirm your password"
                    style="pointer-events: auto; position: relative; z-index: 1;" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>

            <!-- Submit Button -->
            <button type="submit" 
                id="register-submit-button"
                class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                style="pointer-events: auto; position: relative; z-index: 1; cursor: pointer;">
                {{ __('Create Account') }}
            </button>
        </form>
    </div>

    <!-- Sign In Link -->
    <div class="mt-6 text-center" style="position: relative; z-index: 1;">
        <p class="text-sm text-gray-600">
            Already have an account? 
            <a href="{{ route('login') }}" 
               id="signin-link"
               class="font-medium text-blue-600 hover:text-blue-500 underline"
               style="pointer-events: auto; position: relative; z-index: 1; cursor: pointer; display: inline-block; padding: 2px 4px;"
               onclick="console.log('Signin link clicked via onclick');">
                Sign in here
            </a>
        </p>
    </div>
</x-guest-layout>
