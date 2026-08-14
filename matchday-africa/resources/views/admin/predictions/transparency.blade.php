@extends('layouts.admin')

@section('title', 'Prediction Transparency')

@section('header')
<div class="flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">🔍 Prediction Transparency</h1>
        <p class="text-gray-600 mt-2">View all predictions vs actual results for complete transparency</p>
    </div>
</div>
@endsection

@section('content')
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Filters -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <form method="GET" action="{{ route('admin.predictions.transparency') }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Prediction Set Filter -->
                        <div>
                            <label for="prediction_set_id" class="block text-sm font-medium text-gray-700 mb-1">Prediction Set</label>
                            <select name="prediction_set_id" id="prediction_set_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Prediction Sets</option>
                                @foreach($predictionSets as $set)
                                    <option value="{{ $set->id }}" {{ request('prediction_set_id') == $set->id ? 'selected' : '' }}>
                                        {{ $set->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Match Filter -->
                        <div>
                            <label for="match_id" class="block text-sm font-medium text-gray-700 mb-1">Match</label>
                            <select name="match_id" id="match_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Matches</option>
                                @foreach($matches as $match)
                                    <option value="{{ $match->id }}" {{ request('match_id') == $match->id ? 'selected' : '' }}>
                                        {{ $match->homeTeam->name }} vs {{ $match->awayTeam->name }} ({{ $match->home_score }}-{{ $match->away_score }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- User Filter -->
                        <div>
                            <label for="user_id" class="block text-sm font-medium text-gray-700 mb-1">User</label>
                            <select name="user_id" id="user_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Users</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" {{ request('user_id') == $user->id ? 'selected' : '' }}>
                                        {{ $user->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" id="status" class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Status</option>
                                <option value="scored" {{ request('status') == 'scored' ? 'selected' : '' }}>Scored</option>
                                <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                            </select>
                        </div>

                        <!-- Date Filters -->
                        <div>
                            <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                            <input type="date" name="date_from" id="date_from" value="{{ request('date_from') }}" 
                                   class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                            <input type="date" name="date_to" id="date_to" value="{{ request('date_to') }}" 
                                   class="w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <!-- Filter Buttons -->
                        <div class="flex gap-2">
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                Filter
                            </button>
                            <a href="{{ route('admin.predictions.transparency') }}" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500">
                                Clear
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Predictions Table -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Predictions Overview</h3>
                        <p class="text-sm text-gray-600">Showing {{ $predictions->count() }} of {{ $predictions->total() }} predictions</p>
                    </div>

                    @if($predictions->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actual Result</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Points</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($predictions as $prediction)
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
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    {{ $prediction->match->homeTeam->name }} vs {{ $prediction->match->awayTeam->name }}
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    {{ $prediction->match->league->name }}
                                                </div>
                                                <div class="text-sm font-medium text-indigo-600">
                                                    {{ $prediction->match->home_score }}-{{ $prediction->match->away_score }}
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                    @if($prediction->prediction_type === 'result') bg-blue-100 text-blue-800
                                                    @elseif($prediction->prediction_type === 'score') bg-green-100 text-green-800
                                                    @elseif($prediction->prediction_type === 'goalscorer') bg-purple-100 text-purple-800
                                                    @else bg-yellow-100 text-yellow-800
                                                    @endif">
                                                    {{ ucfirst(str_replace('_', ' ', $prediction->prediction_type)) }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $prediction->prediction_value }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                @if($prediction->match->home_score !== null && $prediction->match->away_score !== null)
                                                    {{ $prediction->match->home_score }}-{{ $prediction->match->away_score }}
                                                @else
                                                    <span class="text-gray-400">TBD</span>
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
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ $prediction->created_at->format('M j, Y g:i A') }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <a href="{{ route('admin.predictions.match-detail', $prediction->match) }}" 
                                                   class="text-indigo-600 hover:text-indigo-900">
                                                    View Match Details
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="mt-6">
                            {{ $predictions->appends(request()->query())->links() }}
                        </div>
                    @else
                        <div class="text-center py-12">
                            <div class="text-gray-500 text-lg">No predictions found</div>
                            <div class="text-gray-400 text-sm mt-2">Try adjusting your filters</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
