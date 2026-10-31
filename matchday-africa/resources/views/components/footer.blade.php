<footer class="matchday-footer bg-gray-100 text-black">
    <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Company Info -->
            <div>
                <div class="mb-4">
                    <a href="{{ route('home') }}" class="matchday-wordmark" aria-label="Matchday Africa home">
                        <span class="matchday-monogram">MD</span><span>MATCHDAY<small>AFRICA</small></span>
                    </a>
                </div>
                <p class="text-black text-sm mb-4">
                    Your premier destination for football match analysis, predictions, and insights across African and international leagues.
                </p>
                <x-social-follow />
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach(config('social.accounts') as $key => $account)
                        <li><a href="{{ $account['url'] }}" target="_blank" rel="noopener me" class="text-black hover:text-gray-600 transition-colors">{{ $account['handle'] }} on {{ $account['name'] }}</a></li>
                    @endforeach
                </ul>
            </div>

            <!-- Quick Links -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
                <ul class="space-y-2">
                    <li><a href="{{ route('home') }}" class="text-black hover:text-gray-600 transition-colors">Home</a></li>
                    <li><a href="{{ route('matches.index') }}" class="text-black hover:text-gray-600 transition-colors">Live Matches</a></li>
                    <li><a href="{{ route('leagues.index') }}" class="text-black hover:text-gray-600 transition-colors">Leagues</a></li>
                    <li><a href="{{ route('reels.index') }}" class="text-black hover:text-gray-600 transition-colors">20-second reels</a></li>
                    <li><a href="{{ route('war.index') }}" class="text-black hover:text-gray-600 transition-colors">Matchday War</a></li>
                    <li><a href="{{ route('teams.index') }}" class="text-black hover:text-gray-600 transition-colors">Teams</a></li>
                    <li><a href="{{ route('blogs.index') }}" class="text-black hover:text-gray-600 transition-colors">Articles</a></li>
                    @auth
                        <li><a href="{{ route('predictions.index') }}" class="text-black hover:text-gray-600 transition-colors">Predictions</a></li>
                    @endauth
                </ul>
            </div>

            <!-- Contact Info -->
            <div>
                <h3 class="text-lg font-semibold mb-4">Contact Us</h3>
                <div class="space-y-2">
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        <a href="mailto:info@3d7tech.com" class="text-black hover:text-gray-600 transition-colors">info@3d7tech.com</a>
                    </div>
                    <div class="flex items-center">
                        <svg class="w-4 h-4 mr-2 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9v-9m0-9v9"></path>
                        </svg>
                        <span class="text-black">Powered by 3D7 Technologies</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-gray-300 mt-8 pt-6">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="text-black text-sm">
                    © {{ date('Y') }} 3D7 Technologies. All rights reserved.
                </div>
                <div class="flex space-x-6 mt-4 md:mt-0">
                    <a href="#" class="text-black hover:text-gray-600 text-sm transition-colors">Privacy Policy</a>
                    <a href="{{ route('terms') }}" class="text-black hover:text-gray-600 text-sm transition-colors">Terms of Service</a>
                    <a href="#" class="text-black hover:text-gray-600 text-sm transition-colors">Cookie Policy</a>
                </div>
            </div>
        </div>
    </div>
</footer>
