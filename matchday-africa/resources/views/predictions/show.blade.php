@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <!-- Success Message -->
                @if(session('success'))
                    <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                        {{ session('success') }}
                    </div>
                @endif

                <!-- Error Messages -->
                @if($errors->any())
                    <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                        <ul class="list-disc list-inside">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">{{ $prediction->name }}</h1>
                        @if($prediction->description)
                            <p class="text-gray-600 mt-2">{{ $prediction->description }}</p>
                        @endif
                    </div>
                    <a href="{{ route('predictions.index') }}" 
                       class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        ← Back to Predictions
                    </a>
                </div>

                <!-- Social Sharing Section -->
                <div class="mb-6 pb-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            Share this prediction challenge
                        </div>
                        <x-social-share-buttons :content="$prediction" :show-counts="true" />
                    </div>
                </div>

                <!-- Prediction Set Info -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-gray-500">Deadline</div>
                        <div class="text-lg font-semibold">{{ $prediction->prediction_deadline->format('M j, Y H:i') }}</div>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-gray-500">Matches</div>
                        <div class="text-lg font-semibold">{{ $prediction->matches->count() }}</div>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div class="text-lg font-semibold">
                            @if($prediction->isDeadlinePassed())
                                <span class="text-red-600">Closed</span>
                            @else
                                <span class="text-green-600">Open</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if($prediction->isDeadlinePassed())
                    <!-- Results View -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">Prediction deadline has passed</h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>You can view your predictions and results below, but you can no longer make changes.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if($hasSubmitted)
                    <!-- Submitted Predictions -->
                    <div class="mb-6" id="submitted-predictions">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-semibold">Your Predictions</h2>
                            @if(!$prediction->isDeadlinePassed())
                                <button id="edit-button" onclick="toggleEditMode()" 
                                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                    Edit Predictions
                                </button>
                            @endif
                        </div>
                        <div class="space-y-4">
                            @foreach($userPredictions as $userPrediction)
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4">
                                            <div>
                                                <div class="font-medium">{{ $userPrediction->match->homeTeam->name }} vs {{ $userPrediction->match->awayTeam->name }}</div>
                                                <div class="text-sm text-gray-500">{{ $userPrediction->match->league->name }} • {{ $userPrediction->match->match_date->format('M j, Y H:i') }}</div>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm font-medium">{{ ucfirst(str_replace('_', ' ', $userPrediction->prediction_type)) }}</div>
                                            <div class="text-sm text-gray-500">{{ $userPrediction->prediction_value }}</div>
                                            @if($userPrediction->is_correct !== null)
                                                <div class="text-sm font-semibold {{ $userPrediction->is_correct ? 'text-green-600' : 'text-red-600' }}">
                                                    {{ $userPrediction->is_correct ? 'Correct' : 'Incorrect' }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    
                    <!-- Edit Form (Hidden by default) -->
                    @if(!$prediction->isDeadlinePassed())
                        <form id="prediction-form" class="space-y-6" style="display: none;" method="POST" action="{{ route('predictions.update', $prediction) }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="_force_redirect" value="1">
                            <h2 class="text-xl font-semibold mb-4">Edit Your Predictions</h2>
                            
                            <div class="space-y-6">
                                @foreach($prediction->matches as $predictionMatch)
                                    <div class="border border-gray-200 rounded-lg p-6">
                                        <div class="flex items-center justify-between mb-4">
                                            <div>
                                                <div class="text-lg font-semibold">{{ $predictionMatch->match->homeTeam->name }} vs {{ $predictionMatch->match->awayTeam->name }}</div>
                                                <div class="text-sm text-gray-500">{{ $predictionMatch->match->league->name }} • {{ $predictionMatch->match->match_date->format('M j, Y H:i') }}</div>
                                            </div>
                                            <div class="text-sm text-gray-500">{{ $predictionMatch->points_value }} points</div>
                                        </div>

                                        <div class="prediction-input" data-match-id="{{ $predictionMatch->match->id }}" data-prediction-type="{{ $predictionMatch->prediction_type }}">
                                            @if($predictionMatch->prediction_type === 'result')
                                                <div class="grid grid-cols-3 gap-2">
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Home Win" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">
                                                            <div class="font-medium">{{ $predictionMatch->match->homeTeam->name }}</div>
                                                            <div class="text-sm text-gray-500">Win</div>
                                                        </div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Draw" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">
                                                            <div class="font-medium">Draw</div>
                                                        </div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Away Win" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">
                                                            <div class="font-medium">{{ $predictionMatch->match->awayTeam->name }}</div>
                                                            <div class="text-sm text-gray-500">Win</div>
                                                        </div>
                                                    </label>
                                                </div>
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                            @elseif($predictionMatch->prediction_type === 'score')
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">Correct Score</label>
                                                    <div class="flex items-center space-x-4">
                                                        <div class="flex-1">
                                                            <label class="block text-xs text-gray-500 mb-1 font-medium">{{ $predictionMatch->match->homeTeam->name }}</label>
                                                            <input type="number" 
                                                                   name="home_score_{{ $predictionMatch->match->id }}" 
                                                                   min="0" 
                                                                   max="20" 
                                                                   class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-center text-lg font-bold focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                                                                   placeholder="0">
                                                        </div>
                                                        <div class="text-3xl font-bold text-gray-400 mx-2">-</div>
                                                        <div class="flex-1">
                                                            <label class="block text-xs text-gray-500 mb-1 font-medium">{{ $predictionMatch->match->awayTeam->name }}</label>
                                                            <input type="number" 
                                                                   name="away_score_{{ $predictionMatch->match->id }}" 
                                                                   min="0" 
                                                                   max="20" 
                                                                   class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-center text-lg font-bold focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                                                                   placeholder="0">
                                                        </div>
                                                    </div>
                                                    <div class="mt-3 text-center">
                                                        <div class="inline-block bg-gray-100 rounded-lg px-4 py-2">
                                                            <span class="text-sm text-gray-600">Your prediction:</span>
                                                            <span id="score_display_{{ $predictionMatch->match->id }}" class="ml-2 text-lg font-bold text-gray-800">0-0</span>
                                                        </div>
                                                    </div>
                                                    <!-- Hidden input to store the combined score -->
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" id="score_prediction_{{ $predictionMatch->match->id }}" value="0-0">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                                </div>
                                            @elseif($predictionMatch->prediction_type === 'goalscorer')
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">First Goalscorer</label>
                                                    <input type="text" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" 
                                                           placeholder="Player name" 
                                                           class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                                </div>
                                            @elseif($predictionMatch->prediction_type === 'total_goals')
                                                <div class="grid grid-cols-2 gap-2">
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 0.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 0.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 1.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 1.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 2.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 2.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 3.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 3.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 0.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 0.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 1.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 1.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 2.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 2.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 3.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 3.5</div>
                                                    </label>
                                                </div>
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" 
                                        class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded">
                                    Update Predictions
                                </button>
                            </div>
                        </form>
                    @endif
                @else
                    <!-- New Prediction Form -->
                    @if(!$prediction->isDeadlinePassed())
                        <form id="prediction-form" class="space-y-6" method="POST" action="{{ route('predictions.submit', $prediction) }}">
                            @csrf
                            <input type="hidden" name="_force_redirect" value="1">
                            <h2 class="text-xl font-semibold mb-4">Make Your Predictions</h2>
                            
                            <div class="space-y-6">
                                @foreach($prediction->matches as $predictionMatch)
                                    <div class="border border-gray-200 rounded-lg p-6">
                                        <div class="flex items-center justify-between mb-4">
                                            <div>
                                                <div class="text-lg font-semibold">{{ $predictionMatch->match->homeTeam->name }} vs {{ $predictionMatch->match->awayTeam->name }}</div>
                                                <div class="text-sm text-gray-500">{{ $predictionMatch->match->league->name }} • {{ $predictionMatch->match->match_date->format('M j, Y H:i') }}</div>
                                            </div>
                                            <div class="text-sm text-gray-500">{{ $predictionMatch->points_value }} points</div>
                                        </div>

                                        <div class="prediction-input" data-match-id="{{ $predictionMatch->match->id }}" data-prediction-type="{{ $predictionMatch->prediction_type }}">
                                            @if($predictionMatch->prediction_type === 'result')
                                                <div class="grid grid-cols-3 gap-2">
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Home Win" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">
                                                            <div class="font-medium">{{ $predictionMatch->match->homeTeam->name }}</div>
                                                            <div class="text-sm text-gray-500">Win</div>
                                                        </div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Draw" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">
                                                            <div class="font-medium">Draw</div>
                                                        </div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Away Win" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">
                                                            <div class="font-medium">{{ $predictionMatch->match->awayTeam->name }}</div>
                                                            <div class="text-sm text-gray-500">Win</div>
                                                        </div>
                                                    </label>
                                                </div>
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                            @elseif($predictionMatch->prediction_type === 'score')
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">Correct Score</label>
                                                    <div class="flex items-center space-x-4">
                                                        <div class="flex-1">
                                                            <label class="block text-xs text-gray-500 mb-1 font-medium">{{ $predictionMatch->match->homeTeam->name }}</label>
                                                            <input type="number" 
                                                                   name="home_score_{{ $predictionMatch->match->id }}" 
                                                                   min="0" 
                                                                   max="20" 
                                                                   class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-center text-lg font-bold focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                                                                   placeholder="0">
                                                        </div>
                                                        <div class="text-3xl font-bold text-gray-400 mx-2">-</div>
                                                        <div class="flex-1">
                                                            <label class="block text-xs text-gray-500 mb-1 font-medium">{{ $predictionMatch->match->awayTeam->name }}</label>
                                                            <input type="number" 
                                                                   name="away_score_{{ $predictionMatch->match->id }}" 
                                                                   min="0" 
                                                                   max="20" 
                                                                   class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-center text-lg font-bold focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                                                                   placeholder="0">
                                                        </div>
                                                    </div>
                                                    <div class="mt-3 text-center">
                                                        <div class="inline-block bg-gray-100 rounded-lg px-4 py-2">
                                                            <span class="text-sm text-gray-600">Your prediction:</span>
                                                            <span id="score_display_{{ $predictionMatch->match->id }}" class="ml-2 text-lg font-bold text-gray-800">0-0</span>
                                                        </div>
                                                    </div>
                                                    <!-- Hidden input to store the combined score -->
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" id="score_prediction_{{ $predictionMatch->match->id }}" value="0-0">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                                </div>
                                            @elseif($predictionMatch->prediction_type === 'goalscorer')
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">First Goalscorer</label>
                                                    <input type="text" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" 
                                                           placeholder="Player name" 
                                                           class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                    <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                                </div>
                                            @elseif($predictionMatch->prediction_type === 'total_goals')
                                                <div class="grid grid-cols-2 gap-2">
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 0.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 0.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 1.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 1.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 2.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 2.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Over 3.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Over 3.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 0.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 0.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 1.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 1.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 2.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 2.5</div>
                                                    </label>
                                                    <label class="prediction-option cursor-pointer">
                                                        <input type="radio" name="predictions[{{ $predictionMatch->match->id }}][prediction_value]" value="Under 3.5" class="hidden">
                                                        <div class="border border-gray-300 rounded-lg p-3 text-center hover:bg-gray-50">Under 3.5</div>
                                                    </label>
                                                </div>
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][match_id]" value="{{ $predictionMatch->match->id }}">
                                                <input type="hidden" name="predictions[{{ $predictionMatch->match->id }}][prediction_type]" value="{{ $predictionMatch->prediction_type }}">
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" 
                                        class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded">
                                    Submit Predictions
                                </button>
                            </div>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div id="loadingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4">
        <div class="flex items-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <div class="ml-3 text-lg font-medium text-gray-900">Submitting predictions...</div>
        </div>
    </div>
</div>



<script>
let isEditMode = false;

// Initialize form visibility on page load
document.addEventListener("DOMContentLoaded", function() {
    const submittedDiv = document.getElementById("submitted-predictions");
    const formDiv = document.getElementById("prediction-form");
    
    console.log("Page loaded - submittedDiv:", submittedDiv);
    console.log("Page loaded - formDiv:", formDiv);
    
    // Show submitted predictions if they exist, otherwise show form
    if (submittedDiv) {
        submittedDiv.style.display = "block";
        if (formDiv) formDiv.style.display = "none"; // Hide form when showing submitted predictions
    } else if (formDiv) {
        formDiv.style.display = "block"; // Show form for new users
    }
    
    // Add event listeners for score inputs
    document.querySelectorAll("input[name^='home_score_'], input[name^='away_score_']").forEach(function(input) {
        input.addEventListener("input", function() {
            const matchId = this.name.split("_")[2];
            updateScorePrediction(matchId);
        });
    });
    
    // Add event listeners for prediction options
    document.querySelectorAll(".prediction-option input[type='radio']").forEach(function(input) {
        input.addEventListener("change", function() {
            const option = this.closest(".prediction-option");
            if (option) {
                // Remove selection from other options in the same group
                const allOptions = document.querySelectorAll("input[name='" + this.name + "']");
                allOptions.forEach(function(otherInput) {
                    const otherOption = otherInput.closest(".prediction-option");
                    if (otherOption) {
                        otherOption.classList.remove("bg-blue-50", "border-blue-500");
                    }
                });
                
                // Add selection to current option
                option.classList.add("bg-blue-50", "border-blue-500");
            }
        });
    });
    
    // Form submission validation
    const forms = document.querySelectorAll("form[id='prediction-form']");
    forms.forEach(function(form) {
        form.addEventListener("submit", function(e) {
            let hasErrors = false;
            let errorMessages = [];
            
            // Check all prediction inputs
            const predictionInputs = form.querySelectorAll("input[name*='[prediction_value]']");
            predictionInputs.forEach(function(input) {
                if (!input.value || input.value.trim() === "") {
                    const matchId = input.name.match(/\[(\d+)\]/)[1];
                    const matchElement = form.querySelector("[data-match-id='" + matchId + "']");
                    if (matchElement) {
                        const teams = matchElement.querySelector(".text-lg.font-semibold").textContent;
                        errorMessages.push("Please make a prediction for: " + teams);
                        hasErrors = true;
                    }
                }
            });
            
            // Check radio button groups
            const radioGroups = {};
            form.querySelectorAll("input[type='radio'][name*='prediction_value']").forEach(function(input) {
                const groupName = input.name;
                if (!radioGroups[groupName]) {
                    radioGroups[groupName] = false;
                }
                if (input.checked) {
                    radioGroups[groupName] = true;
                }
            });
            
            Object.keys(radioGroups).forEach(function(groupName) {
                if (!radioGroups[groupName]) {
                    const matchId = groupName.match(/\[(\d+)\]/)[1];
                    const matchElement = form.querySelector("[data-match-id='" + matchId + "']");
                    if (matchElement) {
                        const teams = matchElement.querySelector(".text-lg.font-semibold").textContent;
                        errorMessages.push("Please make a prediction for: " + teams);
                        hasErrors = true;
                    }
                }
            });
            
            if (hasErrors) {
                e.preventDefault();
                alert("Please complete all predictions:\n\n" + errorMessages.join("\n"));
                return false;
            }
            
            // Show loading modal and allow form to submit normally
            const loadingModal = document.getElementById("loadingModal");
            if (loadingModal) {
                loadingModal.classList.remove("hidden");
            }
            
            // Ensure this is treated as a regular form submission, not AJAX
            // Remove any AJAX headers that might be set
            if (window.jQuery && window.jQuery.ajaxSetup) {
                window.jQuery.ajaxSetup({
                    headers: {
                        'X-Requested-With': null
                    }
                });
            }
            
            // Don't prevent default - let the form submit normally for redirect
        });
    });
});

function toggleEditMode() {
    try {
        isEditMode = !isEditMode;
        
        const submittedDiv = document.getElementById("submitted-predictions");
        const formDiv = document.getElementById("prediction-form");
        const editButton = document.getElementById("edit-button");
        
        console.log("Edit mode toggled:", isEditMode);
        console.log("submittedDiv:", submittedDiv);
        console.log("formDiv:", formDiv);
        console.log("editButton:", editButton);
        
        if (isEditMode) {
            // Show form, hide submitted predictions
            if (submittedDiv) submittedDiv.style.display = "none";
            if (formDiv) formDiv.style.display = "block";
            if (editButton) {
                editButton.textContent = "Cancel Edit";
                editButton.classList.remove("bg-blue-600");
                editButton.classList.add("bg-red-600");
            }
            
            // Pre-fill form with existing predictions
            prefillFormWithExistingPredictions();
        } else {
            // Hide form, show submitted predictions
            if (submittedDiv) submittedDiv.style.display = "block";
            if (formDiv) formDiv.style.display = "none";
            if (editButton) {
                editButton.textContent = "Edit Predictions";
                editButton.classList.remove("bg-red-600");
                editButton.classList.add("bg-blue-600");
            }
        }
    } catch (error) {
        console.error("Error in toggleEditMode:", error);
        alert("Edit mode is not available for this prediction set.");
    }
}

function prefillFormWithExistingPredictions() {
    try {
        console.log("Pre-filling form with existing predictions...");
        @if($hasSubmitted)
            @foreach($userPredictions as $userPrediction)
                @if($userPrediction->prediction_type === "score")
                    const homeScore_{{ $userPrediction->match->id }} = document.querySelector("input[name='home_score_{{ $userPrediction->match->id }}']");
                    const awayScore_{{ $userPrediction->match->id }} = document.querySelector("input[name='away_score_{{ $userPrediction->match->id }}']");
                    if (homeScore_{{ $userPrediction->match->id }} && awayScore_{{ $userPrediction->match->id }}) {
                        const scores = "{{ $userPrediction->prediction_value }}".split("-");
                        homeScore_{{ $userPrediction->match->id }}.value = scores[0] || "0";
                        awayScore_{{ $userPrediction->match->id }}.value = scores[1] || "0";
                        updateScorePrediction({{ $userPrediction->match->id }});
                    }
                @else
                    const radio_{{ $userPrediction->match->id }} = document.querySelector("input[name='predictions[{{ $userPrediction->match->id }}][prediction_value]'][value='{{ $userPrediction->prediction_value }}']");
                    if (radio_{{ $userPrediction->match->id }}) {
                        radio_{{ $userPrediction->match->id }}.checked = true;
                        const option = radio_{{ $userPrediction->match->id }}.closest(".prediction-option");
                        if (option) {
                            option.classList.add("bg-blue-50", "border-blue-500");
                        }
                    }
                @endif
            @endforeach
        @endif
    } catch (error) {
        console.error("Error in prefillFormWithExistingPredictions:", error);
    }
}

function updateScorePrediction(matchId) {
    const homeScore = document.querySelector("input[name='home_score_" + matchId + "']");
    const awayScore = document.querySelector("input[name='away_score_" + matchId + "']");
    const hiddenInput = document.querySelector("input[name='predictions[" + matchId + "][prediction_value]']");
    const displaySpan = document.querySelector("#score_display_" + matchId);
    
    if (homeScore && awayScore && hiddenInput && displaySpan) {
        const home = homeScore.value || "0";
        const away = awayScore.value || "0";
        const prediction = home + "-" + away;
        
        hiddenInput.value = prediction;
        displaySpan.textContent = prediction;
    }
}

function updateScoreDisplay(matchId) {
    updateScorePrediction(matchId);
}
</script>
@endsection
