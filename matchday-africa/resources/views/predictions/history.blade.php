@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">📊 Prediction History</h1>
                        <p class="text-gray-600 mt-2">Track your prediction performance over time</p>
                    </div>
                    <a href="{{ route('predictions.index') }}" 
                       class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        ← Back to Predictions
                    </a>
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

                <!-- Filters -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prediction Set</label>
                            <select name="prediction_set_id" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                <option value="">All Prediction Sets</option>
                                @foreach(\App\Models\PredictionSet::all() as $set)
                                    <option value="{{ $set->id }}" {{ ($filters['prediction_set_id'] ?? '') == $set->id ? 'selected' : '' }}>
                                        {{ $set->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" 
                                   class="w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" 
                                   class="w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Result</label>
                            <select name="is_correct" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                <option value="">All Results</option>
                                <option value="1" {{ ($filters['is_correct'] ?? '') === '1' ? 'selected' : '' }}>Correct Only</option>
                                <option value="0" {{ ($filters['is_correct'] ?? '') === '0' ? 'selected' : '' }}>Incorrect Only</option>
                            </select>
                        </div>
                        <div class="md:col-span-4 flex justify-end">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                @if($predictions->count() > 0)
                    <div class="space-y-4">
                        @foreach($predictions as $prediction)
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
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
                                            <div class="font-medium">{{ $prediction->match->homeTeam->name }} vs {{ $prediction->match->awayTeam->name }}</div>
                                            <div class="text-sm text-gray-500">
                                                {{ $prediction->match->league->name }} • 
                                                {{ $prediction->match->match_date->format('M j, Y H:i') }} • 
                                                {{ $prediction->predictionSet->name }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium">{{ ucfirst(str_replace('_', ' ', $prediction->prediction_type)) }}</div>
                                        <div class="text-sm text-gray-500">{{ $prediction->prediction_value }}</div>
                                        @if($prediction->is_correct !== null)
                                            <div class="text-sm font-semibold {{ $prediction->is_correct ? 'text-green-600' : 'text-red-600' }}">
                                                {{ $prediction->is_correct ? 'Correct' : 'Incorrect' }}
                                            </div>
                                            @if($prediction->points_earned > 0)
                                                <div class="text-sm text-blue-600 font-semibold">+{{ $prediction->points_earned }} points</div>
                                            @endif
                                        @else
                                            <div class="text-sm text-gray-500">Pending</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Pagination -->
                    <div class="mt-6">
                        {{ $predictions->appends(request()->query())->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No predictions found</h3>
                        <p class="mt-1 text-sm text-gray-500">Start making predictions to see your history here.</p>
                        <div class="mt-6">
                            <a href="{{ route('predictions.index') }}" 
                               class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Make Predictions
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
