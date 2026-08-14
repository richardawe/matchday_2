@extends('layouts.admin')

@section('title', 'Match Details')

@section('header')
<div class="flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">⚽ {{ $match->homeTeam->name }} vs {{ $match->awayTeam->name }}</h1>
        <p class="text-gray-600 mt-2">{{ $match->league->name }} • {{ $match->match_date->format('M j, Y H:i') }}</p>
    </div>
    <div class="flex space-x-3">
        <a href="{{ route('admin.matches.index') }}" 
           class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
            ← Back to Matches
        </a>
        @if($match->status === 'finished')
            <button onclick="forceScore({{ $match->id }})" 
                    class="bg-purple-500 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
                🎯 Force Score Predictions
            </button>
        @endif
    </div>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Match Overview -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Match Info -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">📋 Match Information</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <div class="flex items-center space-x-4 mb-4">
                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                            <img src="{{ $match->homeTeam->logo_url ?? asset('images/default-team.png') }}" 
                                 alt="{{ $match->homeTeam->name }}" 
                                 class="w-12 h-12 rounded-full object-cover">
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-gray-900">{{ $match->homeTeam->name }}</h4>
                            <p class="text-sm text-gray-500">Home Team</p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <div class="flex items-center space-x-4 mb-4">
                        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                            <img src="{{ $match->awayTeam->logo_url ?? asset('images/default-team.png') }}" 
                                 alt="{{ $match->awayTeam->name }}" 
                                 class="w-12 h-12 rounded-full object-cover">
                        </div>
                        <div>
                            <h4 class="text-xl font-bold text-gray-900">{{ $match->awayTeam->name }}</h4>
                            <p class="text-sm text-gray-500">Away Team</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Match Score -->
            <div class="text-center my-8">
                @if($match->home_score !== null && $match->away_score !== null)
                    <div class="text-6xl font-bold text-gray-900 mb-2">
                        {{ $match->home_score }} - {{ $match->away_score }}
                    </div>
                @else
                    <div class="text-4xl font-bold text-gray-400 mb-2">- -</div>
                @endif
                
                <div class="flex items-center justify-center space-x-4 text-sm text-gray-500">
                    <span>{{ $match->league->name }}</span>
                    <span>•</span>
                    <span>{{ $match->match_date->format('M j, Y H:i') }}</span>
                </div>
            </div>
            
            <!-- Match Status -->
            <div class="text-center">
                <span class="px-4 py-2 rounded-full text-sm font-medium
                    @if($match->status === 'finished') bg-green-100 text-green-800
                    @elseif($match->status === 'scheduled') bg-yellow-100 text-yellow-800
                    @elseif($match->status === 'live') bg-red-100 text-red-800
                    @elseif($match->status === 'cancelled') bg-gray-100 text-gray-800
                    @else bg-blue-100 text-blue-800
                    @endif">
                    {{ ucfirst($match->status) }}
                </span>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">⚡ Quick Actions</h3>
            
            <div class="space-y-4">
                <button onclick="updateScoreModal()" 
                        class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                    📝 Update Score
                </button>
                
                @if($match->status === 'finished')
                    <button onclick="forceScore({{ $match->id }})" 
                            class="w-full bg-purple-500 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg">
                        🎯 Force Score Predictions
                    </button>
                @endif
                
                <button onclick="refreshMatch()" 
                        class="w-full bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">
                    🔄 Refresh Data
                </button>
            </div>
            
            <!-- Match Stats -->
            <div class="mt-6 pt-6 border-t border-gray-200">
                <h4 class="text-sm font-medium text-gray-900 mb-3">Match Statistics</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Created:</span>
                        <span class="text-gray-900">{{ $match->created_at->format('M j, Y H:i') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Last Updated:</span>
                        <span class="text-gray-900">{{ $match->updated_at->format('M j, Y H:i') }}</span>
                    </div>
                    @if($match->scored_at)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Scored At:</span>
                            <span class="text-gray-900">{{ $match->scored_at->format('M j, Y H:i') }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Prediction Sets -->
    @if($predictionSets->count() > 0)
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">🎯 Prediction Sets</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($predictionSets as $predictionSet)
                    <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors duration-200">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900">{{ $predictionSet->name }}</h4>
                            <span class="px-2 py-1 rounded-full text-xs font-medium
                                @if($predictionSet->status === 'active') bg-green-100 text-green-800
                                @elseif($predictionSet->status === 'draft') bg-yellow-100 text-yellow-800
                                @else bg-gray-100 text-gray-800
                                @endif">
                                {{ ucfirst($predictionSet->status) }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-600 mb-3">{{ Str::limit($predictionSet->description, 100) }}</p>
                        <div class="flex items-center justify-between text-sm text-gray-500">
                            <span>Deadline: {{ Carbon\Carbon::parse($predictionSet->prediction_deadline)->format('M j, H:i') }}</span>
                            <a href="{{ route('admin.predictions.show', $predictionSet->id) }}" 
                               class="text-blue-600 hover:text-blue-900">View Details</a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- User Predictions -->
    @if($userPredictions->count() > 0)
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">👥 User Predictions ({{ $userPredictions->count() }})</h3>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction Set</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prediction</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Points</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($userPredictions as $prediction)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">{{ $prediction->user_name }}</div>
                                        <div class="text-sm text-gray-500">{{ $prediction->user_email }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $prediction->prediction_set_name }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ ucfirst(str_replace('_', ' ', $prediction->prediction_type)) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        @if($prediction->prediction_type === 'score')
                                            {{ $prediction->home_score_prediction }} - {{ $prediction->away_score_prediction }}
                                        @elseif($prediction->prediction_type === 'goalscorer')
                                            {{ $prediction->goalscorer_name }}
                                        @elseif($prediction->prediction_type === 'total_goals')
                                            {{ $prediction->total_goals_prediction }}
                                        @else
                                            {{ $prediction->prediction_value }}
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $prediction->points_earned ?? 0 }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium
                                        @if($prediction->is_correct !== null)
                                            @if($prediction->is_correct) bg-green-100 text-green-800
                                            @else bg-red-100 text-red-800
                                            @endif
                                        @else bg-yellow-100 text-yellow-800
                                        @endif">
                                        @if($prediction->is_correct !== null)
                                            {{ $prediction->is_correct ? 'Correct' : 'Incorrect' }}
                                        @else
                                            Pending
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No predictions yet</h3>
            <p class="text-gray-500">Users haven't made predictions on this match yet.</p>
        </div>
    @endif
</div>

<!-- Update Score Modal -->
<div id="updateScoreModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">Update Match Score</h3>
                <button onclick="closeUpdateScoreModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form id="updateScoreForm">
                <input type="hidden" name="match_id" value="{{ $match->id }}">
                
                <div class="mb-4">
                    <div class="text-center text-lg font-semibold text-gray-900">
                        {{ $match->homeTeam->name }} vs {{ $match->awayTeam->name }}
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Home Score</label>
                        <input type="number" name="home_score" value="{{ $match->home_score ?? 0 }}" min="0" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Away Score</label>
                        <input type="number" name="away_score" value="{{ $match->away_score ?? 0 }}" min="0" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" 
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="scheduled" {{ $match->status === 'scheduled' ? 'selected' : '' }}>Scheduled</option>
                        <option value="live" {{ $match->status === 'live' ? 'selected' : '' }}>Live</option>
                        <option value="finished" {{ $match->status === 'finished' ? 'selected' : '' }}>Finished</option>
                        <option value="cancelled" {{ $match->status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        <option value="postponed" {{ $match->status === 'postponed' ? 'selected' : '' }}>Postponed</option>
                    </select>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeUpdateScoreModal()" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg">
                        Update Score
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Update Score Modal Functions
function updateScoreModal() {
    document.getElementById('updateScoreModal').classList.remove('hidden');
}

function closeUpdateScoreModal() {
    document.getElementById('updateScoreModal').classList.add('hidden');
}

// Form Submission
document.getElementById('updateScoreForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const matchId = formData.get('match_id');
    
    fetch(`/admin/matches/${matchId}/update-score`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            home_score: parseInt(formData.get('home_score')),
            away_score: parseInt(formData.get('away_score')),
            status: formData.get('status'),
            force_scoring: true
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating the match');
    });
});

// Force Score Function
function forceScore(matchId) {
    if (confirm('Are you sure you want to force score all predictions for this match?')) {
        fetch(`/admin/matches/${matchId}/force-score`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while scoring predictions');
        });
    }
}

// Refresh Function
function refreshMatch() {
    location.reload();
}
</script>
@endsection
