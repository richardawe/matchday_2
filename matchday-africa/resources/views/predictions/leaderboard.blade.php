@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">🏆 Leaderboard</h1>
                        <p class="text-gray-600 mt-2">See how you rank against other users</p>
                    </div>
                    <div class="flex space-x-2">
                        <a href="{{ route('predictions.index') }}" 
                           class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Back to Predictions
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <form id="leaderboardFilters" class="flex flex-wrap gap-4 items-end">
                        @csrf
                        <div class="flex-1 min-w-48">
                            <label for="prediction_set_id" class="block text-sm font-medium text-gray-700 mb-1">Prediction Set</label>
                            <select id="prediction_set_id" name="prediction_set_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">All Prediction Sets</option>
                                @foreach($predictionSets ?? [] as $set)
                                    <option value="{{ $set->id }}" {{ request('prediction_set_id') == $set->id ? 'selected' : '' }}>
                                        {{ $set->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div class="flex-1 min-w-32">
                            <label for="period" class="block text-sm font-medium text-gray-700 mb-1">Time Period</label>
                            <select id="period" name="period" class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="all_time" {{ request('period', 'all_time') == 'all_time' ? 'selected' : '' }}>All Time</option>
                                <option value="monthly" {{ request('period') == 'monthly' ? 'selected' : '' }}>This Month</option>
                                <option value="weekly" {{ request('period') == 'weekly' ? 'selected' : '' }}>This Week</option>
                                <option value="daily" {{ request('period') == 'daily' ? 'selected' : '' }}>Today</option>
                            </select>
                        </div>
                        
                        <div class="flex-1 min-w-32">
                            <label for="limit" class="block text-sm font-medium text-gray-700 mb-1">Show</label>
                            <select id="limit" name="limit" class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="10" {{ request('limit', '10') == '10' ? 'selected' : '' }}>Top 10</option>
                                <option value="25" {{ request('limit') == '25' ? 'selected' : '' }}>Top 25</option>
                                <option value="50" {{ request('limit') == '50' ? 'selected' : '' }}>Top 50</option>
                                <option value="100" {{ request('limit') == '100' ? 'selected' : '' }}>Top 100</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Filter
                        </button>
                    </form>
                </div>

                <!-- User Stats Summary -->
                @if(auth()->check() && isset($userStats))
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="text-sm font-medium text-blue-600">Your Rank</div>
                        <div class="text-2xl font-bold text-blue-900">{{ $userStats['rank'] ?? 'N/A' }}</div>
                    </div>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="text-sm font-medium text-green-600">Total Points</div>
                        <div class="text-2xl font-bold text-green-900">{{ $userStats['total_points'] ?? 0 }}</div>
                    </div>
                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                        <div class="text-sm font-medium text-purple-600">Accuracy</div>
                        <div class="text-2xl font-bold text-purple-900">{{ number_format($userStats['accuracy_percentage'] ?? 0, 1) }}%</div>
                    </div>
                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                        <div class="text-sm font-medium text-orange-600">Predictions</div>
                        <div class="text-2xl font-bold text-orange-900">{{ $userStats['total_predictions'] ?? 0 }}</div>
                    </div>
                </div>
                @endif

                <!-- Leaderboard Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Points</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Accuracy</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Predictions</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Correct</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Activity</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($leaderboard ?? [] as $index => $entry)
                                <tr class="{{ $entry->user_id === auth()->id() ? 'bg-blue-50 border-l-4 border-blue-500' : '' }} hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            @if($index < 3)
                                                <span class="text-2xl">
                                                    @if($index === 0) 🥇
                                                    @elseif($index === 1) 🥈
                                                    @else 🥉
                                                    @endif
                                                </span>
                                            @else
                                                <span class="text-lg font-semibold text-gray-900">#{{ $entry->rank ?? $index + 1 }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="h-10 w-10 flex-shrink-0">
                                                <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                    <span class="text-sm font-medium text-gray-700">
                                                        {{ substr($entry->user->name ?? 'User', 0, 2) }}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ $entry->user->name ?? 'Anonymous User' }}
                                                    @if($entry->user_id === auth()->id())
                                                        <span class="text-blue-600 text-xs">(You)</span>
                                                    @endif
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    Joined {{ $entry->user->created_at->format('M Y') ?? 'Unknown' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900">{{ number_format($entry->total_points) }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="text-sm font-medium text-gray-900">{{ number_format($entry->accuracy_percentage, 1) }}%</div>
                                            <div class="ml-2 w-16 bg-gray-200 rounded-full h-2">
                                                <div class="bg-green-500 h-2 rounded-full" style="width: {{ min($entry->accuracy_percentage, 100) }}%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $entry->total_predictions }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $entry->correct_predictions }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $entry->updated_at->diffForHumans() }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                        <div class="text-lg font-medium mb-2">No leaderboard data available</div>
                                        <div class="text-sm">Try adjusting your filters or check back later</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if(isset($leaderboard) && $leaderboard->hasPages())
                    <div class="mt-6">
                        {{ $leaderboard->appends(request()->query())->links() }}
                    </div>
                @endif

                <!-- Real-time Update Indicator -->
                <div id="realtime-indicator" class="mt-4 text-center text-sm text-gray-500 hidden">
                    <span class="inline-flex items-center">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Updating leaderboard...
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh leaderboard every 30 seconds
    let refreshInterval;
    
    function startAutoRefresh() {
        refreshInterval = setInterval(function() {
            refreshLeaderboard();
        }, 30000); // 30 seconds
    }
    
    function stopAutoRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
    }
    
    function refreshLeaderboard() {
        const indicator = document.getElementById('realtime-indicator');
        indicator.classList.remove('hidden');
        
        const form = document.getElementById('leaderboardFilters');
        const formData = new FormData(form);
        
        fetch('{{ route("predictions.leaderboard") }}', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the leaderboard table
                updateLeaderboardTable(data.leaderboard);
            }
        })
        .catch(error => {
            console.error('Error refreshing leaderboard:', error);
        })
        .finally(() => {
            indicator.classList.add('hidden');
        });
    }
    
    function updateLeaderboardTable(leaderboardData) {
        // This would update the table with new data
        // For now, we'll just reload the page
        window.location.reload();
    }
    
    // Start auto-refresh when page loads
    startAutoRefresh();
    
    // Stop auto-refresh when user is interacting with filters
    const filterForm = document.getElementById('leaderboardFilters');
    filterForm.addEventListener('submit', function(e) {
        stopAutoRefresh();
    });
    
    // Restart auto-refresh after filter submission
    filterForm.addEventListener('submit', function(e) {
        setTimeout(startAutoRefresh, 1000);
    });
});
</script>
@endsection