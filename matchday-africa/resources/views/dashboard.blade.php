@extends('layouts.app')

@section('content')
<div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Welcome Section -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Welcome back, {{ auth()->user()->name }}!</h1>
                <p class="text-gray-600 mt-2">Stay updated with the latest football matches and make your predictions</p>
            </div>

            <!-- Quick Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-blue-50 rounded-lg p-6">
                    <div class="flex items-center">
                        <div class="p-2 bg-blue-100 rounded-lg">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-blue-600">Total Predictions</p>
                            <p class="text-2xl font-bold text-blue-900">{{ $userStats['total_predictions'] ?? 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-green-50 rounded-lg p-6">
                    <div class="flex items-center">
                        <div class="p-2 bg-green-100 rounded-lg">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-green-600">Correct Predictions</p>
                            <p class="text-2xl font-bold text-green-900">{{ $userStats['correct_predictions'] ?? 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-purple-50 rounded-lg p-6">
                    <div class="flex items-center">
                        <div class="p-2 bg-purple-100 rounded-lg">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-purple-600">Accuracy Rate</p>
                            <p class="text-2xl font-bold text-purple-900">{{ $userStats['accuracy_percentage'] ?? 0 }}%</p>
                        </div>
                    </div>
                </div>

                <div class="bg-orange-50 rounded-lg p-6">
                    <div class="flex items-center">
                        <div class="p-2 bg-orange-100 rounded-lg">
                            <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-orange-600">Total Points</p>
                            <p class="text-2xl font-bold text-orange-900">{{ $userStats['total_points'] ?? 0 }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- Predictions Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">🎯 Predictions</h3>
                            <a href="{{ route('predictions.index') }}" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View All</a>
                        </div>
                        <p class="text-gray-600 mb-4">Make predictions on upcoming matches and compete with other users</p>
                        <div class="flex space-x-3">
                            <a href="{{ route('predictions.index') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                                Make Predictions
                            </a>
                            <a href="{{ route('predictions.history') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                                View History
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Leaderboard Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900">🏆 Leaderboard</h3>
                            <a href="{{ route('predictions.leaderboard') }}" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View All</a>
                        </div>
                        <p class="text-gray-600 mb-4">See how you rank against other prediction experts</p>
                        <div class="flex space-x-3">
                            <a href="{{ route('predictions.leaderboard') }}" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm">
                                View Leaderboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Matches -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">⚽ Recent Matches</h3>
                        <a href="{{ route('matches.index') }}" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View All</a>
                    </div>
                    <div class="space-y-3">
                        @forelse($recentMatches as $match)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center space-x-4">
                                    <div class="text-sm font-medium text-gray-900">{{ $match->homeTeam->name }}</div>
                                    <div class="text-sm text-gray-500">vs</div>
                                    <div class="text-sm font-medium text-gray-900">{{ $match->awayTeam->name }}</div>
                                </div>
                                <div class="text-sm text-gray-500">
                                    {{ $match->match_date->format('M d, Y') }}
                                </div>
                            </div>
                        @empty
                            <p class="text-gray-500 text-center py-4">No recent matches found</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection