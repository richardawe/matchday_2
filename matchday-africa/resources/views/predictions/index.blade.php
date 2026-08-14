@extends('layouts.app')

@section('content')
<x-sponsor-slot slot="predictions" />
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">🎯 Predictions</h1>
                        <p class="text-gray-600 mt-2">Make your predictions and compete with other users</p>
                    </div>
                    <div class="flex space-x-2">
                        <a href="{{ route('predictions.history') }}" 
                           class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                            My History
                        </a>
                        <a href="{{ route('predictions.leaderboard') }}" 
                           class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Leaderboard
                        </a>
                    </div>
                </div>

                <!-- User Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                    <div class="bg-blue-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-blue-600">Total Predictions</div>
                        <div class="text-2xl font-bold text-blue-900">{{ $userStats['total_predictions'] }}</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-green-600">Correct Predictions</div>
                        <div class="text-2xl font-bold text-green-900">{{ $userStats['correct_predictions'] }}</div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-purple-600">Accuracy Rate</div>
                        <div class="text-2xl font-bold text-purple-900">{{ $userStats['accuracy_percentage'] }}%</div>
                    </div>
                    <div class="bg-orange-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-orange-600">Total Points</div>
                        <div class="text-2xl font-bold text-orange-900">{{ $userStats['total_points'] }}</div>
                    </div>
                </div>

                <!-- Recent Predictions Performance -->
                @if($userStats['total_predictions'] > 0)
                <div class="bg-white rounded-lg border border-gray-200 p-6 mb-8">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">📊 Your Recent Performance</h2>
                    
                    <!-- Performance Breakdown -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="text-center">
                            <div class="text-3xl font-bold text-green-600">{{ $userStats['correct_predictions'] }}</div>
                            <div class="text-sm text-gray-600">Correct Predictions</div>
                            <div class="text-xs text-gray-500 mt-1">{{ $userStats['accuracy_percentage'] }}% accuracy</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-600">{{ $userStats['total_predictions'] - $userStats['correct_predictions'] }}</div>
                            <div class="text-sm text-gray-600">Incorrect Predictions</div>
                            <div class="text-xs text-gray-500 mt-1">{{ 100 - $userStats['accuracy_percentage'] }}% miss rate</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-blue-600">{{ $userStats['total_points'] }}</div>
                            <div class="text-sm text-gray-600">Total Points Earned</div>
                            <div class="text-xs text-gray-500 mt-1">Across all predictions</div>
                        </div>
                    </div>

                    <!-- Recent Predictions List -->
                    <div class="border-t pt-4">
                        <h3 class="text-lg font-medium text-gray-900 mb-3">Recent Predictions</h3>
                        <div class="space-y-3">
                            @forelse($recentPredictions ?? [] as $prediction)
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="flex-shrink-0">
                                            @if($prediction->is_correct === true)
                                                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                                    <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                    </svg>
                                                </div>
                                            @elseif($prediction->is_correct === false)
                                                <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                                                    <svg class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                                    </svg>
                                                </div>
                                            @else
                                                <div class="w-8 h-8 bg-gray-100 rounded-full flex items-center justify-center">
                                                    <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                                    </svg>
                                                </div>
                                            @endif
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900">{{ $prediction->match->homeTeam->name }} vs {{ $prediction->match->awayTeam->name }}</div>
                                            <div class="text-sm text-gray-500">
                                                {{ ucfirst(str_replace('_', ' ', $prediction->prediction_type)) }}: {{ $prediction->prediction_value }}
                                                @if($prediction->is_correct !== null)
                                                    • {{ $prediction->match->home_score }}-{{ $prediction->match->away_score }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        @if($prediction->is_correct !== null)
                                            <div class="text-sm font-semibold {{ $prediction->is_correct ? 'text-green-600' : 'text-red-600' }}">
                                                {{ $prediction->is_correct ? 'Correct' : 'Incorrect' }}
                                            </div>
                                            <div class="text-sm text-blue-600 font-semibold">+{{ $prediction->points_earned }} pts</div>
                                        @else
                                            <div class="text-sm text-gray-500">Pending</div>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-4 text-gray-500">
                                    <p>No recent predictions found.</p>
                                </div>
                            @endforelse
                        </div>
                        
                        @if(($recentPredictions ?? collect())->count() > 0)
                            <div class="mt-4 text-center">
                                <a href="{{ route('predictions.history') }}" 
                                   class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                                    View All Predictions →
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                @if($predictionSets->count() > 0)
                    <div class="space-y-6">
                        @foreach($predictionSets as $predictionSet)
                            <div class="border border-gray-200 rounded-lg p-6 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between mb-4">
                                    <div>
                                        <h3 class="text-xl font-semibold text-gray-900">{{ $predictionSet->name }}</h3>
                                        @if($predictionSet->description)
                                            <p class="text-gray-600 mt-1">{{ $predictionSet->description }}</p>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm text-gray-500">Deadline</div>
                                        <div class="font-semibold">{{ $predictionSet->prediction_deadline->format('M j, Y H:i') }}</div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-gray-900">{{ $predictionSet->matches->count() }}</div>
                                        <div class="text-sm text-gray-500">Matches</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-gray-900">{{ $predictionSet->getParticipationCount() }}</div>
                                        <div class="text-sm text-gray-500">Participants</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-gray-900">
                                            @if($predictionSet->isDeadlinePassed())
                                                <span class="text-red-600">Closed</span>
                                            @else
                                                <span class="text-green-600">Open</span>
                                            @endif
                                        </div>
                                        <div class="text-sm text-gray-500">Status</div>
                                    </div>
                                    <div class="text-center">
                                        @php
                                            $userPredictionStats = $predictionSet->userPredictions()
                                                ->where('user_id', auth()->id())
                                                ->get();
                                            $userPoints = $userPredictionStats->sum('points_earned');
                                            $userCorrect = $userPredictionStats->where('is_correct', true)->count();
                                        @endphp
                                        <div class="text-2xl font-bold text-blue-600">{{ $userPoints }}</div>
                                        <div class="text-sm text-gray-500">Your Points</div>
                                        @if($userPredictionStats->count() > 0)
                                            <div class="text-xs text-gray-400">{{ $userCorrect }}/{{ $userPredictionStats->count() }} correct</div>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <div class="text-sm text-gray-500">
                                        Created {{ $predictionSet->created_at->diffForHumans() }}
                                    </div>
                                    <div class="flex space-x-2">
                                        @if(!$predictionSet->isDeadlinePassed())
                                            <a href="{{ route('predictions.show', $predictionSet) }}" 
                                               class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                                                @if($predictionSet->userPredictions()->where('user_id', auth()->id())->exists())
                                                    Update Predictions
                                                @else
                                                    Make Predictions
                                                @endif
                                            </a>
                                        @else
                                            <a href="{{ route('predictions.show', $predictionSet) }}" 
                                               class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded text-sm">
                                                View Results
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No prediction sets available</h3>
                        <p class="mt-1 text-sm text-gray-500">Check back later for new prediction opportunities.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
