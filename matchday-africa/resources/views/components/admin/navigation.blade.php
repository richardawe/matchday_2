<nav class="matchday-nav matchday-admin-nav bg-gray-800 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <!-- Logo -->
            <div class="flex items-center">
                <a href="{{ route('admin.dashboard') }}" class="matchday-wordmark">
                    <span class="matchday-monogram">MD</span><span>MATCHDAY<small>COMMAND DESK</small></span>
                </a>
            </div>

            <!-- Navigation Links -->
            <div class="hidden md:flex items-center space-x-8">
                <!-- Dashboard -->
                <a href="{{ route('admin.dashboard') }}" 
                   class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('admin.dashboard') ? 'bg-gray-900 text-white' : 'text-gray-300 hover:text-white hover:bg-gray-700' }}">
                    📊 Dashboard
                </a>

                <!-- Content Management -->
                <div class="relative group" x-data="{ open: false }">
                    <button @click="open = !open" class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('admin.blogs.*') || request()->routeIs('admin.match-previews.*') ? 'bg-gray-900 text-white' : 'text-gray-300 hover:text-white hover:bg-gray-700' }}">
                        📝 Content
                        <svg class="w-4 h-4 inline ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" style="display: none;">
                        <a href="{{ route('admin.blogs.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">📰 Blogs</a>
                        <a href="{{ route('admin.match-previews.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">⚽ Match Previews</a>
                    </div>
                </div>

                <!-- Predictions -->
                <div class="relative group" x-data="{ open: false }">
                    <button @click="open = !open" class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('admin.predictions.*') ? 'bg-gray-900 text-white' : 'text-gray-300 hover:text-white hover:bg-gray-700' }}">
                        🎯 Predictions
                        <svg class="w-4 h-4 inline ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" style="display: none;">
                        <a href="{{ route('admin.predictions.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">📋 Prediction Sets</a>
                        <a href="{{ route('admin.predictions.transparency') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">🔍 Transparency</a>
                        <a href="{{ route('admin.predictions.analytics') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">📊 Analytics</a>
                    </div>
                </div>

                <!-- Match Management -->
                <div class="relative group" x-data="{ open: false }">
                    <button @click="open = !open" class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('admin.matches.*') ? 'bg-gray-900 text-white' : 'text-gray-300 hover:text-white hover:bg-gray-700' }}">
                        ⚽ Matches
                        <svg class="w-4 h-4 inline ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" style="display: none;">
                        <a href="{{ route('admin.matches.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">📋 All Matches</a>
                        <a href="{{ route('admin.matches.index', ['status' => 'finished']) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">✅ Finished Matches</a>
                        <a href="{{ route('admin.matches.index', ['status' => 'scheduled']) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">⏰ Scheduled Matches</a>
                    </div>
                </div>

                <!-- Data Management -->
                <div class="relative group" x-data="{ open: false }">
                    <button @click="open = !open" class="px-3 py-2 rounded-md text-sm font-medium {{ request()->routeIs('admin.sync.*') ? 'bg-gray-900 text-white' : 'text-gray-300 hover:text-white hover:bg-gray-700' }}">
                        🔄 Data
                        <svg class="w-4 h-4 inline ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" style="display: none;">
                        <a href="{{ route('admin.sync.index') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">🔄 Sync Data</a>
                    </div>
                </div>

                <!-- System -->
                <div class="relative group" x-data="{ open: false }">
                    <button @click="open = !open" class="px-3 py-2 rounded-md text-sm font-medium text-gray-300 hover:text-white hover:bg-gray-700">
                        ⚙️ System
                        <svg class="w-4 h-4 inline ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" style="display: none;">
                        <a href="{{ route('admin.api.status') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">🔌 API Status</a>
                        <form method="POST" action="{{ route('admin.cache.clear') }}" class="block">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">🗑️ Clear Cache</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- User Menu -->
            <div class="flex items-center">
                <div class="relative group" x-data="{ open: false }">
                    <button @click="open = !open" class="flex items-center text-sm font-medium text-gray-300 hover:text-white">
                        <span class="mr-2">{{ Auth::user()->name }}</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" style="display: none;">
                        <a href="{{ route('dashboard') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">🏠 User Dashboard</a>
                        <a href="{{ route('home') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">🌐 Public Site</a>
                        <div class="border-t border-gray-100"></div>
                        <form method="POST" action="{{ route('logout') }}" class="block">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">🚪 Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>
