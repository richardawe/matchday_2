@extends('layouts.public')

@section('content')
<x-sponsor-slot slot="home" />
@auth
<section class="md-command"><div class="md-wrap">
<div class="md-command-head"><div><p class="md-eyebrow">YOUR MATCHDAY COMMAND CENTRE</p><h1>Welcome back, {{ Str::before(auth()->user()->name, ' ') }}.</h1><p>Fixtures, calls and stories from the clubs under your banner.</p></div><a class="md-secondary" href="{{ route('onboarding') }}">{{ $followedTeams->isEmpty()?'Choose clubs':'Edit clubs' }}</a></div>
@if($followedTeams->isEmpty())<a href="{{ route('onboarding') }}" class="md-onboard"><strong>Your watchtower is empty.</strong><span>Choose the teams you follow to unlock a personal matchday feed →</span></a>
@else<div class="md-following">@foreach($followedTeams as $team)<span>@if($team->logo_url)<img src="{{ $team->logo_url }}" alt="">@endif{{ $team->display_name }}</span>@endforeach</div>@endif
<div class="md-command-grid"><div class="md-command-main"><div class="md-section-title"><p>YOUR NEXT BATTLES</p><a href="{{ route('matches.index') }}">All matches</a></div>
@forelse($personalMatches as $fixture)<a class="md-fixture" href="{{ route('matches.show',$fixture) }}"><small>{{ $fixture->league?->name }} · {{ $fixture->match_date->isToday()?'TODAY':strtoupper($fixture->match_date->format('D j M')) }}</small><div><span>{{ $fixture->homeTeam?->name }}</span><b>{{ $fixture->home_score!==null?$fixture->home_score:$fixture->match_date->format('H:i') }} — {{ $fixture->away_score!==null?$fixture->away_score:'' }}</b><span>{{ $fixture->awayTeam?->name }}</span></div></a>@empty<div class="md-panel"><p>No followed-club fixtures in the next seven days.</p></div>@endforelse</div>
<aside><div class="md-section-title"><p>MAKE YOUR CALL</p><a href="{{ route('predictions.index') }}">Predict</a></div>@forelse($openPredictionSets as $set)<a class="md-predict-card" href="{{ route('predictions.show',$set) }}"><small>Closes {{ $set->prediction_deadline->diffForHumans() }}</small><strong>{{ $set->name }}</strong><span>{{ $set->matches_count }} calls waiting →</span></a>@empty<div class="md-panel"><p>No open challenges.</p></div>@endforelse<a class="md-league-link" href="{{ route('groups.index') }}">Create or join a prediction league →</a></aside></div>
</div></section>
@endauth
<style>
.text-shadow-lg {
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
}
.text-shadow-md {
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
}
</style>
<!-- Hero Section -->
<div class="matchday-home-hero bg-gradient-to-r from-blue-600 to-green-600 text-white py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <p class="matchday-kicker">THE CONTINENT'S FOOTBALL WATCHTOWER</p>
            <h1 class="text-3xl font-bold mb-2">Today's Football<br><i>becomes history.</i></h1>
            <p class="text-xl text-blue-100">Fixtures, scores and stories · {{ $today }}</p>
            <a href="{{ route('war.index') }}" class="matchday-hero-cta">Enter Matchday War →</a>
        </div>
    </div>
</div>

<!-- Sign-up banner for non-authenticated users -->
@guest
    <div class="bg-gradient-to-r from-yellow-400 to-orange-500 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <div class="flex items-center justify-center space-x-4">
                    <div class="text-2xl">⚽</div>
                    <div class="text-black">
                        <h2 class="text-2xl font-bold mb-2">Join the Matchday Community!</h2>
                        <p class="text-black text-lg mb-3">Get personalized match updates, join live chats, and never miss a moment</p>
                        <div class="flex justify-center space-x-4">
                            <a href="{{ route('register') }}" class="bg-white text-orange-600 hover:bg-gray-100 px-6 py-2 rounded-lg font-semibold transition-colors">
                                Sign Up Free
                            </a>
                            <a href="{{ route('login') }}" class="text-black border-2 border-black hover:bg-black hover:text-white px-6 py-2 rounded-lg font-semibold transition-colors">
                                Sign In
                            </a>
                        </div>
                    </div>
                    <div class="text-2xl">⚽</div>
                </div>
            </div>
        </div>
    </div>
@endguest

<!-- Quick Access Section -->
<div class="matchday-quick-access bg-white border border-gray-200 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <h2 class="text-2xl font-bold text-black mb-4">Quick Access</h2>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="{{ route('matches.index') }}" 
                   class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-200 flex items-center space-x-2">
                    <span>⚽</span>
                    <span>Live Matches</span>
                </a>
                <a href="{{ route('odds.index') }}" 
                   class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-200 flex items-center space-x-2">
                    <span>📊</span>
                    <span>EPL Betting Odds</span>
                </a>
                <a href="{{ route('leagues.index') }}" 
                   class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-200 flex items-center space-x-2">
                    <span>🏆</span>
                    <span>All Leagues</span>
                </a>
                @auth
                    <a href="{{ route('predictions.index') }}" 
                       class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-200 flex items-center space-x-2">
                        <span>🎯</span>
                        <span>Predictions</span>
                    </a>
                @endauth
            </div>
        </div>
    </div>
</div>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        
        <!-- Live Matches Section (Priority) -->
        @if($hasLiveMatches)
            <div class="bg-red-50 border-l-4 border-red-500 overflow-hidden shadow-sm sm:rounded-lg mb-8">
                <div class="p-6">
                    <h2 class="text-xl font-bold text-red-800 mb-4 flex items-center">
                        🔴 LIVE MATCHES
                    </h2>
                    
                    <div class="space-y-4">
                        @foreach($liveMatches as $match)
                            <a href="{{ route('matches.show', $match) }}" class="block bg-white border border-red-200 rounded-lg p-4 hover:bg-red-50 transition-colors">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="text-xs text-red-600 mb-2 font-semibold">
                                            {{ $match->league ? $match->league->name : 'Unknown League' }} • {{ $match->status }} • {{ $match->match_date->format('H:i') }}
                                        </div>
                                        
                                        <div class="flex items-center space-x-4">
                                            <!-- Home Team -->
                                            <div class="flex-1 flex items-center space-x-3">
                                                <img 
                                                    src="{{ $match->homeTeam ? $match->homeTeam->logo : '' }}" 
                                                    alt="Home Team"
                                                    class="w-10 h-10 object-contain"
                                                    onerror="this.style.display='none'"
                                                >
                                                <span class="font-semibold">{{ $match->homeTeam ? $match->homeTeam->name : 'Home Team' }}</span>
                                            </div>
                                            
                                            <!-- Live Score -->
                                            <div class="text-center px-6">
                                                <span class="text-2xl font-bold text-red-600">
                                                    @if($match->home_score !== null && $match->away_score !== null)
                                                        {{ $match->home_score }} - {{ $match->away_score }}
                                                    @else
                                                        vs
                                                    @endif
                                                </span>
                                                <div class="text-xs text-red-600 font-semibold mt-1">LIVE</div>
                                            </div>
                                            
                                            <!-- Away Team -->
                                            <div class="flex-1 flex items-center justify-end space-x-3">
                                                <span class="font-semibold">{{ $match->awayTeam ? $match->awayTeam->name : 'Away Team' }}</span>
                                                <img 
                                                    src="{{ $match->awayTeam ? $match->awayTeam->logo : '' }}" 
                                                    alt="Away Team"
                                                    class="w-10 h-10 object-contain"
                                                    onerror="this.style.display='none'"
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        
        <!-- Today's Matches Section -->
        @if($hasMatchesToday)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-8">
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                        📅 Today's Matches ({{ $todaysMatches->count() }})
                    </h2>
                    
                    <div class="space-y-4">
                        @foreach($todaysMatches as $match)
                            <a href="{{ route('matches.show', $match) }}" class="block border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="text-sm text-gray-600 mb-2">
                                            <span class="font-medium">{{ $match->league ? $match->league->name : 'Unknown League' }}</span>
                                            <span class="mx-2">•</span>
                                            <span class="font-semibold">{{ $match->match_date->format('H:i') }}</span>
                                            @if($match->status && $match->status !== 'SCHEDULED')
                                                <span class="mx-2">•</span>
                                                <span class="text-blue-600 font-semibold">{{ $match->status }}</span>
                                            @endif
                                        </div>
                                        
                                        <div class="flex items-center space-x-4">
                                            <!-- Home Team -->
                                            <div class="flex-1 flex items-center space-x-3">
                                                <img 
                                                    src="{{ $match->homeTeam ? $match->homeTeam->logo : '' }}" 
                                                    alt="Home Team"
                                                    class="w-10 h-10 object-contain"
                                                    onerror="this.style.display='none'"
                                                >
                                                <span class="font-medium">{{ $match->homeTeam ? $match->homeTeam->name : 'Home Team' }}</span>
                                            </div>
                                            
                                            <!-- Score/Status -->
                                            <div class="text-center px-6">
                                                <span class="text-xl font-bold">
                                                    @if($match->home_score !== null && $match->away_score !== null)
                                                        {{ $match->home_score }} - {{ $match->away_score }}
                                                    @else
                                                        vs
                                                    @endif
                                                </span>
                                            </div>
                                            
                                            <!-- Away Team -->
                                            <div class="flex-1 flex items-center justify-end space-x-3">
                                                <span class="font-medium">{{ $match->awayTeam ? $match->awayTeam->name : 'Away Team' }}</span>
                                                <img 
                                                    src="{{ $match->awayTeam ? $match->awayTeam->logo : '' }}" 
                                                    alt="Away Team"
                                                    class="w-10 h-10 object-contain"
                                                    onerror="this.style.display='none'"
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- Featured Blog Posts Section -->
        @if($hasFeaturedBlogs)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-8">
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                        📰 Latest News & Articles
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($featuredBlogs as $blog)
                            <article class="bg-gray-50 border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition-shadow">
                                @if($blog->featured_image)
                                    <div class="aspect-w-16 aspect-h-9">
                                        <img src="{{ $blog->featured_image_url }}" 
                                             alt="{{ $blog->title }}" 
                                             class="w-full h-48 object-cover">
                                    </div>
                                @endif
                                
                                <div class="p-4">
                                    <div class="flex items-center text-xs text-gray-500 mb-2">
                                        <span>{{ $blog->author_name }}</span>
                                        <span class="mx-2">•</span>
                                        <span>{{ $blog->formatted_published_date }}</span>
                                        <span class="mx-2">•</span>
                                        <span>{{ $blog->reading_time }}</span>
                                    </div>
                                    
                                    <h3 class="font-bold text-lg text-gray-900 mb-2 line-clamp-2">
                                        {{ $blog->title }}
                                    </h3>
                                    
                                    <p class="text-gray-600 text-sm mb-4 line-clamp-3">
                                        {{ $blog->excerpt }}
                                    </p>
                                    
                                    @if(isset($blog->metadata['category']))
                                        <div class="mb-3">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                {{ $blog->metadata['category'] }}
                                            </span>
                                        </div>
                                    @endif
                                    
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center text-xs text-gray-500">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                            {{ number_format($blog->view_count) }} views
                                        </div>
                                        
                                        <a href="{{ route('blogs.show', $blog) }}" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                            Read More →
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    
                    <div class="mt-6 text-center">
                        <a href="{{ route('blogs.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            View All Articles
                            <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                        
                        @guest
                            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-sm text-blue-700 mb-3">
                                    <strong>Want to comment and interact?</strong> Sign up for free to join the conversation!
                                </p>
                                <div class="flex justify-center space-x-3">
                                    <a href="{{ route('register') }}" class="text-sm bg-blue-600 text-white px-4 py-2 rounded font-medium hover:bg-blue-700 transition-colors">
                                        Sign Up Free
                                    </a>
                                    <a href="{{ route('login') }}" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                        Already have an account? Sign In
                                    </a>
                                </div>
                            </div>
                        @endguest
                    </div>
                </div>
            </div>
        @endif

        <!-- Featured Match Previews Section -->
        @if($hasFeaturedPreviews)
            <div class="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <div class="flex items-center mb-6">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-indigo-500 rounded-full flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-lg font-semibold text-purple-900">Featured Match Previews</h3>
                            <p class="text-sm text-purple-700">AI-powered insights for upcoming matches</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($featuredPreviews as $preview)
                            <div class="bg-white rounded-lg shadow-md border border-purple-100 overflow-hidden hover:shadow-lg transition-shadow">
                                <div class="p-6">
                                    <!-- Match Header -->
                                    <div class="flex items-center justify-between mb-4">
                                        <div class="flex items-center space-x-3">
                                            <div class="text-center">
                                                <img src="{{ $preview->match->homeTeam->logo }}" alt="{{ $preview->match->homeTeam->name }}" class="w-8 h-8 object-contain">
                                                <div class="text-xs font-medium text-gray-600 mt-1">{{ $preview->match->homeTeam->name }}</div>
                                            </div>
                                            <div class="text-gray-400">vs</div>
                                            <div class="text-center">
                                                <img src="{{ $preview->match->awayTeam->logo }}" alt="{{ $preview->match->awayTeam->name }}" class="w-8 h-8 object-contain">
                                                <div class="text-xs font-medium text-gray-600 mt-1">{{ $preview->match->awayTeam->name }}</div>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                            Featured
                                        </span>
                                    </div>
                                    
                                    <!-- League and Date -->
                                    <div class="text-sm text-gray-600 mb-3">
                                        <div class="font-medium">{{ $preview->match->league->name }}</div>
                                        <div>{{ $preview->match->match_date->format('M j, Y H:i') }}</div>
                                    </div>
                                    
                                    <!-- Preview Content -->
                                    <div class="mb-4">
                                        <p class="text-sm text-gray-700 line-clamp-3">
                                            {{ Str::limit(strip_tags($preview->preview_content), 120) }}
                                        </p>
                                    </div>
                                    
                                    <!-- Actions -->
                                    <div class="flex items-center justify-between">
                                        <a href="{{ route('matches.show', $preview->match) }}" 
                                           class="text-purple-600 hover:text-purple-800 text-sm font-medium">
                                            View Match Details →
                                        </a>
                                        <div class="flex items-center text-xs text-gray-500">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            {{ $preview->generated_at->diffForHumans() }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    
                    <div class="mt-6 text-center">
                        <a href="{{ route('matches.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                            View All Match Previews
                            <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        @endif

        <!-- Featured Leagues Section (Secondary) -->
        @if($featuredLeagues->count() > 0)
            <div class="bg-gray-50 overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4 text-gray-800">Featured Leagues</h3>
                    
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        @foreach($featuredLeagues as $league)
                            <a href="{{ route('leagues.show', $league) }}" class="block text-center p-4 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors cursor-pointer">
                                @if($league->logo_url)
                                    <img src="{{ $league->logo_url }}" alt="{{ $league->name }}" class="w-12 h-12 mx-auto mb-2 object-contain">
                                @else
                                    <div class="w-12 h-12 mx-auto mb-2 bg-gray-200 rounded-full flex items-center justify-center">
                                        <span class="text-xs font-bold">{{ substr($league->name, 0, 2) }}</span>
                                    </div>
                                @endif
                                
                                <div class="text-sm font-medium">{{ $league->name }}</div>
                                <div class="text-xs text-gray-500">{{ $league->country_name }}</div>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- No Matches Today Message -->
        @if(!$hasMatchesToday)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-8 text-center">
                    <div class="text-6xl mb-4">⚽</div>
                    <h3 class="text-xl font-semibold mb-2 text-gray-800">No Matches Scheduled Today</h3>
                    <p class="text-gray-600 mb-4">Check back later or browse our featured leagues for upcoming matches.</p>
                    <a href="{{ route('matches.index') }}" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors">
                        View All Matches
                    </a>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
