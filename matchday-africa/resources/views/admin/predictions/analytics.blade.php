@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">📊 Prediction Analytics</h1>
                        <p class="text-gray-600 mt-2">Comprehensive analytics and insights for prediction system</p>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="exportAnalytics()" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                            Export Data
                        </button>
                        <a href="{{ route('admin.predictions.index') }}" 
                           class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <form id="analyticsFilters" class="flex flex-wrap gap-4 items-end">
                        @csrf
                        <div class="flex-1 min-w-48">
                            <label for="prediction_set_id" class="block text-sm font-medium text-gray-700 mb-1">Prediction Set</label>
                            <select id="prediction_set_id" name="prediction_set_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">All Prediction Sets</option>
                                @foreach($predictionSets as $set)
                                    <option value="{{ $set->id }}" {{ request('prediction_set_id') == $set->id ? 'selected' : '' }}>
                                        {{ $set->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div class="flex-1 min-w-32">
                            <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                            <input type="date" id="date_from" name="date_from" 
                                   value="{{ request('date_from', now()->subDays(30)->format('Y-m-d')) }}"
                                   class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <div class="flex-1 min-w-32">
                            <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                            <input type="date" id="date_to" name="date_to" 
                                   value="{{ request('date_to', now()->format('Y-m-d')) }}"
                                   class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        
                        <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Filter
                        </button>
                    </form>
                </div>

                <!-- Key Metrics -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                                    <span class="text-white text-sm font-bold">U</span>
                                </div>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-blue-600">Total Users</div>
                                <div class="text-2xl font-bold text-blue-900">{{ number_format($analytics['basic_stats']['unique_users'] ?? 0) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center">
                                    <span class="text-white text-sm font-bold">P</span>
                                </div>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-green-600">Total Predictions</div>
                                <div class="text-2xl font-bold text-green-900">{{ number_format($analytics['basic_stats']['total_predictions'] ?? 0) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-purple-500 rounded-full flex items-center justify-center">
                                    <span class="text-white text-sm font-bold">A</span>
                                </div>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-purple-600">Accuracy Rate</div>
                                <div class="text-2xl font-bold text-purple-900">{{ number_format($analytics['basic_stats']['accuracy_percentage'] ?? 0, 1) }}%</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-orange-50 border border-orange-200 rounded-lg p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-orange-500 rounded-full flex items-center justify-center">
                                    <span class="text-white text-sm font-bold">M</span>
                                </div>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-orange-600">Matches</div>
                                <div class="text-2xl font-bold text-orange-900">{{ number_format($analytics['basic_stats']['matches_count'] ?? 0) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Participation Chart -->
                    <div class="bg-white border border-gray-200 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Participation Over Time</h3>
                        <div id="participationChart" class="h-64">
                            <!-- Chart will be rendered here -->
                            <div class="flex items-center justify-center h-full text-gray-500">
                                Chart loading...
                            </div>
                        </div>
                    </div>

                    <!-- Accuracy by Prediction Type -->
                    <div class="bg-white border border-gray-200 rounded-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Accuracy by Prediction Type</h3>
                        <div id="accuracyChart" class="h-64">
                            <!-- Chart will be rendered here -->
                            <div class="flex items-center justify-center h-full text-gray-500">
                                Chart loading...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Performers -->
                <div class="bg-white border border-gray-200 rounded-lg p-6 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Top Performers</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Points</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Accuracy</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Predictions</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Joined</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($analytics['top_performers'] ?? [] as $index => $performer)
                                    <tr class="hover:bg-gray-50">
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
                                                    <span class="text-lg font-semibold text-gray-900">#{{ $index + 1 }}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="h-10 w-10 flex-shrink-0">
                                                    <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                        <span class="text-sm font-medium text-gray-700">
                                                            {{ substr($performer->user->name ?? 'User', 0, 2) }}
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        {{ $performer->user->name ?? 'Anonymous User' }}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                            {{ number_format($performer->total_points) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="text-sm font-medium text-gray-900">{{ number_format($performer->accuracy_percentage, 1) }}%</div>
                                                <div class="ml-2 w-16 bg-gray-200 rounded-full h-2">
                                                    <div class="bg-green-500 h-2 rounded-full" style="width: {{ min($performer->accuracy_percentage, 100) }}%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $performer->total_predictions }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $performer->user->created_at->format('M Y') ?? 'Unknown' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                            No data available
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Prediction Set Performance -->
                <div class="bg-white border border-gray-200 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Prediction Set Performance</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction Set</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Participants</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Predictions</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Accuracy</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($predictionSets as $set)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">{{ $set->name }}</div>
                                            <div class="text-sm text-gray-500">{{ Str::limit($set->description, 50) }}</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $set->userPredictions()->distinct('user_id')->count() }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $set->userPredictions()->count() }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @php
                                                $totalPredictions = $set->userPredictions()->count();
                                                $correctPredictions = $set->userPredictions()->where('is_correct', true)->count();
                                                $accuracy = $totalPredictions > 0 ? round(($correctPredictions / $totalPredictions) * 100, 1) : 0;
                                            @endphp
                                            <div class="flex items-center">
                                                <div class="text-sm font-medium text-gray-900">{{ $accuracy }}%</div>
                                                <div class="ml-2 w-16 bg-gray-200 rounded-full h-2">
                                                    <div class="bg-green-500 h-2 rounded-full" style="width: {{ min($accuracy, 100) }}%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                                @if($set->status === 'active') bg-green-100 text-green-800
                                                @elseif($set->status === 'closed') bg-red-100 text-red-800
                                                @elseif($set->status === 'draft') bg-yellow-100 text-yellow-800
                                                @else bg-gray-100 text-gray-800
                                                @endif">
                                                {{ ucfirst($set->status) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $set->created_at->format('M j, Y') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                            No prediction sets found
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function exportAnalytics() {
    const form = document.getElementById('analyticsFilters');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    
    window.open(`{{ route('admin.predictions.analytics.export') }}?${params.toString()}`, '_blank');
}

// Initialize charts when page loads
document.addEventListener('DOMContentLoaded', function() {
    try {
        // Participation Chart
        const participationCtx = document.getElementById('participationChart');
        if (participationCtx) {
            const participationChart = new Chart(participationCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: {!! json_encode($analytics['participation']['dates'] ?? []) !!},
                    datasets: [{
                        label: 'Daily Predictions',
                        data: {!! json_encode($analytics['participation']['daily_predictions'] ?? []) !!},
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.1
                    }, {
                        label: 'Daily Users',
                        data: {!! json_encode($analytics['participation']['daily_users'] ?? []) !!},
                        borderColor: 'rgb(16, 185, 129)',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // Accuracy Chart
        const accuracyCtx = document.getElementById('accuracyChart');
        if (accuracyCtx) {
            const accuracyChart = new Chart(accuracyCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: {!! json_encode($analytics['accuracy']['types'] ?? []) !!},
                    datasets: [{
                        data: {!! json_encode($analytics['accuracy']['values'] ?? []) !!},
                        backgroundColor: [
                            'rgb(59, 130, 246)',
                            'rgb(16, 185, 129)',
                            'rgb(245, 158, 11)',
                            'rgb(239, 68, 68)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    } catch (error) {
        console.error('Error initializing charts:', error);
        // Hide chart loading messages and show error
        document.querySelectorAll('.chart-loading').forEach(el => {
            el.innerHTML = 'Chart failed to load';
            el.className = 'text-red-500 text-center py-4';
        });
    }
});
</script>
@endsection
