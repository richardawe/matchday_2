@extends('layouts.admin')

@section('title', 'Match Predictions Detail')

@section('header')
<div class="flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">⚽ Match Predictions Detail</h1>
        <p class="text-gray-600 mt-2">Detailed view of all predictions for this specific match</p>
    </div>
    <a href="{{ route('admin.predictions.transparency') }}" class="bg-indigo-500 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
        ← Back to Transparency View
    </a>
</div>
@endsection

@section('content')
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Match Information -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">
                                {{ $match->homeTeam->name }} vs {{ $match->awayTeam->name }}
                            </h3>
                            <div class="mt-2 text-lg text-gray-600">
                                {{ $match->league->name }} • {{ $match->match_date->format('M j, Y g:i A') }}
                            </div>
                            <div class="mt-2 text-3xl font-bold text-indigo-600">
                                {{ $match->home_score ?? '?' }}-{{ $match->away_score ?? '?' }}
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-gray-500">Status</div>
                            <div class="text-lg font-medium
                                @if($match->status === 'finished') text-green-600
                                @elseif($match->status === 'live') text-red-600
                                @else text-gray-600
                                @endif">
                                {{ ucfirst($match->status) }}
                            </div>
                            @if($match->scored_at)
                                <div class="text-sm text-gray-500 mt-1">
                                    Scored: {{ $match->scored_at->format('M j, Y g:i A') }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Prediction Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-2xl font-bold text-gray-900">{{ $predictions->count() }}</div>
                        <div class="text-sm text-gray-600">Total Predictions</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-2xl font-bold text-green-600">{{ $predictions->where('is_correct', true)->count() }}</div>
                        <div class="text-sm text-gray-600">Correct Predictions</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-2xl font-bold text-red-600">{{ $predictions->where('is_correct', false)->count() }}</div>
                        <div class="text-sm text-gray-600">Incorrect Predictions</div>
                    </div>
                </div>
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-2xl font-bold text-yellow-600">{{ $predictions->whereNull('is_correct')->count() }}</div>
                        <div class="text-sm text-gray-600">Pending</div>
                    </div>
                </div>
            </div>

            <!-- Predictions by Type -->
            @foreach($predictionsByType as $type => $typePredictions)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6">
                        <h4 class="text-lg font-medium text-gray-900 mb-4">
                            {{ ucfirst(str_replace('_', ' ', $type)) }} Predictions
                            <span class="text-sm text-gray-500">({{ $typePredictions->count() }} predictions)</span>
                        </h4>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Points</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction Set</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($typePredictions as $prediction)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8">
                                                        <div class="h-8 w-8 rounded-full bg-indigo-100 flex items-center justify-center">
                                                            <span class="text-sm font-medium text-indigo-600">
                                                                {{ substr($prediction->user->name, 0, 1) }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="ml-3">
                                                        <div class="text-sm font-medium text-gray-900">{{ $prediction->user->name }}</div>
                                                        <div class="text-sm text-gray-500">{{ $prediction->user->email }}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <div class="font-medium">{{ $prediction->prediction_value }}</div>
                                                @if($type === 'result')
                                                    <div class="text-xs text-gray-500">
                                                        @if($prediction->prediction_value === 'H') Home Win
                                                        @elseif($prediction->prediction_value === 'D') Draw
                                                        @elseif($prediction->prediction_value === 'A') Away Win
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                @if($prediction->is_correct === null)
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                        Pending
                                                    </span>
                                                @elseif($prediction->is_correct)
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        ✓ Correct
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                        ✗ Incorrect
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($prediction->points_earned !== null)
                                                    <span class="font-medium text-green-600">+{{ $prediction->points_earned }}</span>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $prediction->predictionSet->name ?? 'N/A' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ $prediction->created_at->format('M j, Y g:i A') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach

            @if($predictions->count() === 0)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-12 text-center">
                        <div class="text-gray-500 text-lg">No predictions found for this match</div>
                        <div class="text-gray-400 text-sm mt-2">Users haven't made any predictions yet</div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
