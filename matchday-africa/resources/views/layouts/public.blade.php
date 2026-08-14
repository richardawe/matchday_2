<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Social Meta Tags -->
        <x-social-meta :content="$content ?? null" />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased matchday-shell">
        <div class="min-h-screen matchday-canvas">
            <!-- Public Navigation -->
            <nav x-data="{ open: false }" class="matchday-nav bg-white border-b border-gray-100">
                <!-- Primary Navigation Menu -->
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex justify-between h-16">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="shrink-0 flex items-center">
                                <a href="{{ route('home') }}" class="matchday-wordmark flex items-center">
                                    <span class="matchday-monogram">MD</span><span>MATCHDAY<small>AFRICA</small></span>
                                </a>
                            </div>

                            <!-- Navigation Links -->
                            <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <x-nav-link :href="route('home')" :active="request()->routeIs('home')">
                                    {{ __('Home') }}
                                </x-nav-link>
                                <x-nav-link :href="route('matches.index')" :active="request()->routeIs('matches.*')">
                                    {{ __('Matches') }}
                                </x-nav-link>
                                @auth<x-nav-link :href="route('predictions.index')" :active="request()->routeIs('predictions.*')">{{ __('Predict') }}</x-nav-link>@endauth
                                <x-nav-link :href="route('leagues.index')" :active="request()->routeIs('leagues.*')">
                                    {{ __('Community') }}
                                </x-nav-link>
                                <x-nav-link :href="route('war.index')" :active="request()->routeIs('war.*')">
                                    {{ __('War') }}
                                </x-nav-link>
                                <x-nav-link :href="route('discovery.index')" :active="request()->routeIs('discovery.*')">{{ __('Players') }}</x-nav-link>
                                <x-nav-link :href="route('teams.index')" :active="request()->routeIs('teams.*')">
                                    {{ __('Teams') }}
                                </x-nav-link>
                                <x-nav-link :href="route('blogs.index')" :active="request()->routeIs('blogs.*')">
                                    {{ __('📰 Articles') }}
                                </x-nav-link>
                                <x-nav-link :href="route('shop.index')" :active="request()->routeIs('shop.*')">{{ __('Shop') }}</x-nav-link>
                                <x-nav-link :href="route('premium.index')" :active="request()->routeIs('premium.*')">{{ __('Premium') }}</x-nav-link>
                            </div>
                        </div>

                        <!-- Authentication Links -->
                        <div class="hidden sm:flex sm:items-center sm:ms-6">
                            @auth
                                <!-- User is logged in -->
                                <x-dropdown align="right" width="48">
                                    <x-slot name="trigger">
                                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                                            <div>{{ Auth::user()->name }}</div>

                                            <div class="ms-1">
                                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                        </button>
                                    </x-slot>

                                    <x-slot name="content">
                                        <x-dropdown-link :href="route('dashboard')">
                                            {{ __('Dashboard') }}
                                        </x-dropdown-link>
                                        <x-dropdown-link :href="route('profile.edit')">
                                            {{ __('Profile') }}
                                        </x-dropdown-link>
                                        <x-dropdown-link :href="route('groups.index')">{{ __('Prediction leagues') }}</x-dropdown-link>
                                        <x-dropdown-link :href="route('notification-settings')">{{ __('Alerts & digest') }}</x-dropdown-link>
                                        <x-dropdown-link :href="route('library.index')">{{ __('Digital library') }}</x-dropdown-link>

                                        <!-- Authentication -->
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <x-dropdown-link :href="route('logout')"
                                                    onclick="event.preventDefault();
                                                                this.closest('form').submit();">
                                                {{ __('Log Out') }}
                                            </x-dropdown-link>
                                        </form>
                                    </x-slot>
                                </x-dropdown>
                            @else
                                <!-- User is not logged in -->
                                <div class="space-x-4">
                                    <a href="{{ route('login') }}" class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm font-medium">
                                        {{ __('Login') }}
                                    </a>
                                    <a href="{{ route('register') }}" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium">
                                        {{ __('Register') }}
                                    </a>
                                </div>
                            @endauth
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                    <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
                    <div class="pt-2 pb-3 space-y-1">
                        <x-responsive-nav-link :href="route('home')" :active="request()->routeIs('home')">
                            {{ __('Home') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('matches.index')" :active="request()->routeIs('matches.*')">
                            {{ __('Live Matches') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('leagues.index')" :active="request()->routeIs('leagues.*')">
                            {{ __('Leagues') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('war.index')" :active="request()->routeIs('war.*')">
                            {{ __('War') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('teams.index')" :active="request()->routeIs('teams.*')">
                            {{ __('Teams') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('blogs.index')" :active="request()->routeIs('blogs.*')">
                            {{ __('📰 Articles') }}
                        </x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('shop.index')" :active="request()->routeIs('shop.*')">{{ __('Shop') }}</x-responsive-nav-link>
                        <x-responsive-nav-link :href="route('premium.index')" :active="request()->routeIs('premium.*')">{{ __('Premium') }}</x-responsive-nav-link>
                    </div>

                    <!-- Responsive Authentication Links -->
                    <div class="pt-4 pb-1 border-t border-gray-200">
                        @auth
                            <div class="px-4">
                                <div class="font-medium text-base text-gray-800">{{ Auth::user()->name }}</div>
                                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
                            </div>

                            <div class="mt-3 space-y-1">
                                <x-responsive-nav-link :href="route('dashboard')">
                                    {{ __('Dashboard') }}
                                </x-responsive-nav-link>
                                <x-responsive-nav-link :href="route('profile.edit')">
                                    {{ __('Profile') }}
                                </x-responsive-nav-link>

                                <!-- Authentication -->
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-responsive-nav-link :href="route('logout')"
                                            onclick="event.preventDefault();
                                                        this.closest('form').submit();">
                                        {{ __('Log Out') }}
                                    </x-responsive-nav-link>
                                </form>
                            </div>
                        @else
                            <div class="mt-3 space-y-1">
                                <x-responsive-nav-link :href="route('login')">
                                    {{ __('Login') }}
                                </x-responsive-nav-link>
                                <x-responsive-nav-link :href="route('register')">
                                    {{ __('Register') }}
                                </x-responsive-nav-link>
                            </div>
                        @endauth
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            @isset($header)
                <header class="matchday-page-header bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                @yield('content')
            </main>
        </div>

        <!-- Footer -->
        <x-footer />
        
        @stack('scripts')
        <script>
        (()=>{let id=document.cookie.match(/md_visitor=([^;]+)/)?.[1];if(!id){id=(crypto.randomUUID?.()||Date.now()+'-'+Math.random());document.cookie='md_visitor='+id+';path=/;max-age=31536000;SameSite=Lax'}
        const track=(event,properties={})=>fetch('{{ route('analytics.track') }}',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({event,path:location.pathname,properties}),keepalive:true}).catch(()=>{});
        track('page_view');document.addEventListener('click',e=>{const share=e.target.closest('.md-share');if(share){const data={title:share.dataset.shareTitle,text:share.dataset.shareText,url:location.href};track('share_started',{title:data.title});navigator.share?navigator.share(data):navigator.clipboard.writeText(location.href).then(()=>{share.textContent='Link copied'})}});})();
        </script>
    </body>
</html>
