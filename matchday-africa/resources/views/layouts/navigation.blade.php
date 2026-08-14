<nav x-data="{ open: false }" class="matchday-nav bg-white border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('home') }}" class="matchday-wordmark">
                        <span class="matchday-monogram">MD</span><span>MATCHDAY<small>AFRICA</small></span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link :href="route('home')" :active="request()->routeIs('home')">
                        {{ __('Home') }}
                    </x-nav-link>
                    <x-nav-link :href="route('matches.index')" :active="request()->routeIs('matches.*')">
                        {{ __('Live Matches') }}
                    </x-nav-link>
                    <x-nav-link :href="route('leagues.index')" :active="request()->routeIs('leagues.*')">
                        {{ __('Leagues') }}
                    </x-nav-link>
                    <x-nav-link :href="route('war.index')" :active="request()->routeIs('war.*')">
                        {{ __('War') }}
                    </x-nav-link>
                    <x-nav-link :href="route('teams.index')" :active="request()->routeIs('teams.*')">
                        {{ __('Teams') }}
                    </x-nav-link>
                    <x-nav-link :href="route('odds.index')" :active="request()->routeIs('odds.*')" class="text-green-600">
                        {{ __('📊 EPL Odds') }}
                    </x-nav-link>
                    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        {{ __('Dashboard') }}
                    </x-nav-link>
                    @auth
                        <x-nav-link :href="route('predictions.index')" :active="request()->routeIs('predictions.*')" class="text-purple-600">
                            {{ __('🎯 Predictions') }}
                        </x-nav-link>
                        @if(auth()->user()?->isAdmin())
                            <x-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')" class="text-blue-600">
                                {{ __('🛠️ Admin') }}
                            </x-nav-link>
                        @endif
                    @endauth
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()?->name ?? 'Guest' }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Quick Access Links -->
                        <div class="border-t border-gray-100"></div>
                        <div class="px-4 py-2">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Quick Access</p>
                        </div>
                        
                        <a href="{{ route('odds.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 text-black hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            {{ __('📊 EPL Betting Odds') }}
                        </a>
                        <a href="{{ route('matches.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 text-black hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            {{ __('⚽ Live Matches') }}
                        </a>
                        <a href="{{ route('leagues.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 text-black hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            {{ __('🏆 All Leagues') }}
                        </a>
                        <a href="{{ route('teams.index') }}" class="block w-full px-4 py-2 text-start text-sm leading-5 text-black hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                            {{ __('👥 Teams') }}
                        </a>

                        @if(auth()->user()?->isAdmin())
                            <div class="border-t border-gray-100"></div>
                            <div class="px-4 py-2">
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Admin Tools</p>
                            </div>
                            <x-dropdown-link :href="route('admin.dashboard')">
                                {{ __('🛠️ Admin Dashboard') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('admin.predictions.index')">
                                {{ __('🎯 Manage Predictions') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('admin.blogs.index')">
                                {{ __('📝 Manage Blog') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('admin.match-previews.index')">
                                {{ __('🔮 Match Previews') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('admin.twitter.index')">
                                {{ __('🐦 Twitter Management') }}
                            </x-dropdown-link>
                        @else
                            <div class="border-t border-gray-100"></div>
                            <div class="px-4 py-2">
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">My Activity</p>
                            </div>
                            <x-dropdown-link :href="route('predictions.index')">
                                {{ __('🎯 Make Predictions') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('predictions.history')">
                                {{ __('📊 My Predictions') }}
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('predictions.leaderboard')">
                                {{ __('🏆 Leaderboard') }}
                            </x-dropdown-link>
                        @endif

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
            <x-responsive-nav-link :href="route('teams.index')" :active="request()->routeIs('teams.*')">
                {{ __('Teams') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('odds.index')" :active="request()->routeIs('odds.*')">
                {{ __('📊 EPL Odds') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            @auth
                <x-responsive-nav-link :href="route('predictions.index')" :active="request()->routeIs('predictions.*')">
                    {{ __('🎯 Predictions') }}
                </x-responsive-nav-link>
                @if(auth()->user()?->isAdmin())
                    <x-responsive-nav-link :href="route('admin.dashboard')" :active="request()->routeIs('admin.*')">
                        {{ __('🛠️ Admin') }}
                    </x-responsive-nav-link>
                @endif
            @endauth
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ Auth::user()?->name ?? 'Guest' }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()?->email ?? 'guest@example.com' }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Quick Access Links -->
                <div class="border-t border-gray-200 my-2"></div>
                <div class="px-4 py-1">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Quick Access</p>
                </div>
                
                <a href="{{ route('odds.index') }}" class="block pl-3 pr-4 py-2 border-l-4 border-transparent text-base font-medium text-black hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out">
                    {{ __('📊 EPL Betting Odds') }}
                </a>
                <a href="{{ route('matches.index') }}" class="block pl-3 pr-4 py-2 border-l-4 border-transparent text-base font-medium text-black hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out">
                    {{ __('⚽ Live Matches') }}
                </a>
                <a href="{{ route('leagues.index') }}" class="block pl-3 pr-4 py-2 border-l-4 border-transparent text-base font-medium text-black hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out">
                    {{ __('🏆 All Leagues') }}
                </a>
                <a href="{{ route('teams.index') }}" class="block pl-3 pr-4 py-2 border-l-4 border-transparent text-base font-medium text-black hover:text-gray-800 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-gray-300 transition duration-150 ease-in-out">
                    {{ __('👥 Teams') }}
                </a>

                @if(auth()->user()?->isAdmin())
                    <x-responsive-nav-link :href="route('admin.dashboard')">
                        {{ __('🛠️ Admin Dashboard') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('admin.predictions.index')">
                        {{ __('🎯 Manage Predictions') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('admin.match-previews.index')">
                        {{ __('🔮 Match Previews') }}
                    </x-responsive-nav-link>
                @else
                    <x-responsive-nav-link :href="route('predictions.history')">
                        {{ __('📊 My Predictions') }}
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('predictions.leaderboard')">
                        {{ __('🏆 Leaderboard') }}
                    </x-responsive-nav-link>
                @endif

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
        </div>
    </div>
</nav>
