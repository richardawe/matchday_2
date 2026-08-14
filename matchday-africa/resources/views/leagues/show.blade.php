@extends('layouts.public')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- League Header -->
        <!-- Navigation Tabs -->
        <div class="bg-white border-b border-gray-200 mb-6">
            <nav class="flex space-x-8 px-6" aria-label="Tabs">
                <a href="{{ route("leagues.show", $league) }}" class="py-4 px-1 border-b-2 font-medium text-sm {{ request()->routeIs("leagues.show") ? "border-blue-500 text-blue-600" : "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" }}">
                    Matches
                </a>
                <a href="{{ route("leagues.standings", $league) }}" class="py-4 px-1 border-b-2 font-medium text-sm {{ request()->routeIs("leagues.standings") ? "border-blue-500 text-blue-600" : "border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300" }}">
                    Standings
                </a>
            </nav>
        </div>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
            <div class="p-6">
                <div class="flex items-center space-x-4">
                    @if($league->logo_url)
                        <img src="{{ $league->logo_url }}" alt="{{ $league->name }}" class="w-16 h-16 object-contain">
                    @endif
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">{{ $league->name }}</h1>
                        <p class="text-gray-600">{{ $league->country_name ?? 'International' }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-2xl font-bold text-blue-600">{{ $upcomingMatches->count() }}</div>
                <div class="text-sm text-gray-600">Upcoming Matches</div>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-2xl font-bold text-green-600">{{ $liveMatches->count() }}</div>
                <div class="text-sm text-gray-600">Live Matches</div>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-2xl font-bold text-gray-600">{{ $recentMatches->count() }}</div>
                <div class="text-sm text-gray-600">Recent Results</div>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-2xl font-bold text-purple-600">{{ $teams->count() }}</div>
                <div class="text-sm text-gray-600">Teams</div>
            </div>
        </div>

        <!-- Live Matches -->
        @if($liveMatches->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4 text-green-600">🔴 Live Matches</h3>
                    <div class="space-y-3">
                        @foreach($liveMatches as $match)
                            <div class="border border-green-200 rounded-lg p-4 bg-green-50">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm">{{ $match->homeTeam ? $match->homeTeam->name : 'Home Team' }}</span>
                                        <span class="font-bold text-green-600">
                                            @if($match->home_score !== null && $match->away_score !== null)
                                                {{ $match->home_score }} - {{ $match->away_score }}
                                            @else
                                                vs
                                            @endif
                                        </span>
                                        <span class="text-sm">{{ $match->awayTeam ? $match->awayTeam->name : 'Away Team' }}</span>
                                    </div>
                                    <div class="text-sm text-green-600 font-semibold">{{ $match->status }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- Upcoming Matches -->
        @if($upcomingMatches->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">📅 Upcoming Matches</h3>
                    <div class="space-y-3">
                        @foreach($upcomingMatches as $match)
                            <a href="{{ route('matches.show', $match) }}" class="block border border-gray-200 rounded-lg p-4 hover:bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm">{{ $match->homeTeam ? $match->homeTeam->name : 'Home Team' }}</span>
                                        <span class="font-bold">vs</span>
                                        <span class="text-sm">{{ $match->awayTeam ? $match->awayTeam->name : 'Away Team' }}</span>
                                    </div>
                                    <div class="text-sm text-gray-600">
                                        {{ $match->match_date->format('M d, H:i') }}
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- Recent Results -->
        @if($recentMatches->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">📊 Recent Results</h3>
                    <div class="space-y-3">
                        @foreach($recentMatches as $match)
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <span class="text-sm">{{ $match->homeTeam ? $match->homeTeam->name : 'Home Team' }}</span>
                                        <span class="font-bold">
                                            @if($match->home_score !== null && $match->away_score !== null)
                                                {{ $match->home_score }} - {{ $match->away_score }}
                                            @else
                                                -
                                            @endif
                                        </span>
                                        <span class="text-sm">{{ $match->awayTeam ? $match->awayTeam->name : 'Away Team' }}</span>
                                    </div>
                                    <div class="text-sm text-gray-600">
                                        {{ $match->match_date->format('M d') }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <!-- No Data Message -->
        @if($upcomingMatches->count() == 0 && $liveMatches->count() == 0 && $recentMatches->count() == 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-center">
                    <h3 class="text-lg font-semibold mb-2">No matches available</h3>
                    <p class="text-gray-500">No matches found for {{ $league->name }}. Check back later!</p>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
