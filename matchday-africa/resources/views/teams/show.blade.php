@extends('layouts.public')

@section('content')
<div class="py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Team Header -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    @if($team->logo_url)
                        <img src="{{ $team->logo_url }}" alt="{{ $team->name }}" class="w-16 h-16 object-contain">
                    @else
                        <div class="w-16 h-16 bg-gray-200 rounded-full flex items-center justify-center">
                            <span class="text-gray-500 font-bold text-lg">{{ substr($team->name, 0, 2) }}</span>
                        </div>
                    @endif
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">{{ $team->name }}</h1>
                        @if($team->common_name && $team->common_name !== $team->name)
                            <p class="text-lg text-gray-600">{{ $team->common_name }}</p>
                        @endif
                        @if($team->league)
                            <p class="text-gray-500">{{ $team->league->name }}</p>
                        @endif
                    </div>
                </div>
                <div class="text-right">
                    <a href="{{ route('teams.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        ← Back to Teams
                    </a>
                </div>
            </div>
        </div>

        <!-- Team Navigation -->
        <div class="bg-white rounded-lg shadow-sm border p-4 mb-6">
            <nav class="flex space-x-4">
                <a href="{{ route('teams.show', $team) }}" class="px-4 py-2 text-sm font-medium text-blue-600 border-b-2 border-blue-600">
                    📊 Overview
                </a>
                @if(Route::has('teams.squad'))
                    <a href="{{ route('teams.squad', $team) }}" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 border-b-2 border-transparent hover:border-gray-300">
                        👥 Squad @if(isset($team->players_count) && $team->players_count > 0)({{ $team->players_count }})@endif
                    </a>
                @endif
            </nav>
        </div>

        <!-- Team Statistics -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ $teamStats['total_matches'] ?? 0 }}</div>
                <div class="text-sm text-gray-600">Total Matches</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ $teamStats['wins'] ?? 0 }}</div>
                <div class="text-sm text-gray-600">Wins</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600">{{ $teamStats['draws'] ?? 0 }}</div>
                <div class="text-sm text-gray-600">Draws</div>
            </div>
            <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ $teamStats['losses'] ?? 0 }}</div>
                <div class="text-sm text-gray-600">Losses</div>
            </div>
        </div>

        <!-- Recent Matches -->
        @if($recentMatches && $recentMatches->count() > 0)
            <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
                <h3 class="text-lg font-semibold mb-4">📅 Recent Matches</h3>
                <div class="space-y-3">
                    @foreach($recentMatches as $match)
                        <a href="{{ route('matches.show', $match) }}" 
                           class="block p-3 rounded border hover:bg-gray-50 transition-colors">
                            <div class="flex items-center justify-between">
                                <!-- Match Info -->
                                <div class="flex items-center space-x-4">
                                    @if($match->league)
                                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">
                                            {{ $match->league->name }}
                                        </span>
                                    @endif
                                    <span class="text-sm text-gray-600">
                                        {{ $match->match_date ? $match->match_date->format('M j, Y') : 'TBD' }}
                                    </span>
                                </div>
                                
                                <!-- Teams and Score -->
                                <div class="text-right">
                                    <div class="font-medium">
                                        {{ $match->homeTeam ? $match->homeTeam->name : 'Home' }} 
                                        @if($match->home_score !== null && $match->away_score !== null)
                                            <span class="mx-2 font-bold">{{ $match->home_score }}-{{ $match->away_score }}</span>
                                        @else
                                            <span class="mx-2 text-gray-500">vs</span>
                                        @endif
                                        {{ $match->awayTeam ? $match->awayTeam->name : 'Away' }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ strtoupper($match->status ?? 'SCHEDULED') }}
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Upcoming Matches -->
        @if($upcomingMatches && $upcomingMatches->count() > 0)
            <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
                <h3 class="text-lg font-semibold mb-4">⏭️ Upcoming Matches</h3>
                <div class="space-y-3">
                    @foreach($upcomingMatches as $match)
                        <a href="{{ route('matches.show', $match) }}" 
                           class="block p-3 rounded border hover:bg-gray-50 transition-colors">
                            <div class="flex items-center justify-between">
                                <!-- Match Info -->
                                <div class="flex items-center space-x-4">
                                    @if($match->league)
                                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                            {{ $match->league->name }}
                                        </span>
                                    @endif
                                    <span class="text-sm text-gray-600">
                                        {{ $match->match_date ? $match->match_date->format('M j, Y H:i') : 'TBD' }}
                                    </span>
                                </div>
                                
                                <!-- Teams -->
                                <div class="text-right">
                                    <div class="font-medium">
                                        {{ $match->homeTeam ? $match->homeTeam->name : 'Home' }} 
                                        <span class="mx-2 text-gray-500">vs</span>
                                        {{ $match->awayTeam ? $match->awayTeam->name : 'Away' }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ strtoupper($match->status ?? 'SCHEDULED') }}
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Team Squad Preview (if available) -->
        @if(isset($playersByPosition) && $playersByPosition->count() > 0)
            <div class="bg-white rounded-lg shadow-sm border p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">👥 Squad Preview</h3>
                    @if(Route::has('teams.squad'))
                        <a href="{{ route('teams.squad', $team) }}" 
                           class="text-blue-600 hover:text-blue-800 text-sm">
                            View Full Squad →
                        </a>
                    @endif
                </div>
                
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    @foreach(['Goalkeeper', 'Defender', 'Midfielder', 'Attacker'] as $position)
                        @if($playersByPosition->has($position))
                            <div class="text-center">
                                <div class="text-2xl mb-1">
                                    @switch($position)
                                        @case('Goalkeeper') 🥅 @break
                                        @case('Defender') 🛡️ @break
                                        @case('Midfielder') ⚽ @break
                                        @case('Attacker') 🎯 @break
                                    @endswitch
                                </div>
                                <div class="font-semibold">{{ $playersByPosition[$position]->count() }}</div>
                                <div class="text-xs text-gray-600">{{ $position }}s</div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <!-- No Data Message -->
        @if((!isset($recentMatches) || $recentMatches->count() == 0) && (!isset($upcomingMatches) || $upcomingMatches->count() == 0))
            <div class="bg-white rounded-lg shadow-sm border p-8">
                <div class="text-center">
                    <div class="text-gray-500 text-lg mb-4">⚽</div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No Match Data Available</h3>
                    <p class="text-gray-600">
                        Match information for {{ $team->name }} will appear here when available.
                    </p>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
