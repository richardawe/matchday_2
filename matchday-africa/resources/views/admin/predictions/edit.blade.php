@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Edit Prediction Set</h1>
                        <p class="text-gray-600 mt-2">Update prediction set details and matches</p>
                    </div>
                    <a href="{{ route('admin.predictions.index') }}" 
                       class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        ← Back to Predictions
                    </a>
                </div>

                <form id="predictionForm" class="space-y-6">
                    @csrf
                    @method('PUT')
                    
                    <!-- Basic Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Name *</label>
                            <input type="text" id="name" name="name" value="{{ $prediction->name }}" required
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="prediction_deadline" class="block text-sm font-medium text-gray-700 mb-2">Deadline *</label>
                            <input type="datetime-local" id="prediction_deadline" name="prediction_deadline" 
                                   value="{{ $prediction->prediction_deadline->format('Y-m-d\TH:i') }}" required
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea id="description" name="description" rows="3"
                                  class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">{{ $prediction->description }}</textarea>
                    </div>

                    <!-- Current Matches -->
                    <div>
                        <h3 class="text-lg font-semibold mb-4">Current Matches ({{ $prediction->matches->count() }})</h3>
                        <div class="space-y-3">
                            @foreach($prediction->matches as $predictionMatch)
                                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <div class="font-medium">{{ $predictionMatch->match->homeTeam->name }} vs {{ $predictionMatch->match->awayTeam->name }}</div>
                                            <div class="text-sm text-gray-500">{{ $predictionMatch->match->league->name }} • {{ $predictionMatch->match->match_date->format('M j, Y H:i') }}</div>
                                        </div>
                                        <div class="flex items-center space-x-4">
                                            <div class="text-sm">
                                                <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $predictionMatch->prediction_type)) }}</span>
                                                <span class="text-gray-500">• {{ $predictionMatch->points_value }} pts</span>
                                            </div>
                                            <button type="button" onclick="removeMatch({{ $predictionMatch->id }})" 
                                                    class="text-red-600 hover:text-red-900 text-sm font-medium">
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Add New Matches -->
                    <div>
                        <h3 class="text-lg font-semibold mb-4">Add New Matches</h3>
                        
                        <!-- Filters -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">League</label>
                                <select id="leagueFilter" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                    <option value="">All Leagues</option>
                                    @foreach($leagues as $league)
                                        <option value="{{ $league->id }}">{{ $league->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                                <input type="date" id="dateFromFilter" class="w-full border border-gray-300 rounded-md px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                                <input type="date" id="dateToFilter" class="w-full border border-gray-300 rounded-md px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                <input type="text" id="searchFilter" placeholder="Search teams..." 
                                       class="w-full border border-gray-300 rounded-md px-3 py-2">
                            </div>
                        </div>

                        <!-- Available Matches -->
                        <div class="border border-gray-200 rounded-lg p-4 max-h-96 overflow-y-auto">
                            <h4 class="font-medium mb-3">Available Matches ({{ count($availableMatches) }} found)</h4>
                            <div id="availableMatches" class="space-y-2">
                                @if(count($availableMatches) > 0)
                                    @foreach($availableMatches as $match)
                                        @if(!$prediction->matches->contains('match_id', $match->id))
                                            <div class="match-item border border-gray-200 rounded-lg p-3 hover:bg-gray-50 cursor-pointer"
                                                 data-match-id="{{ $match->id }}" data-league-id="{{ $match->league_id }}">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center space-x-4">
                                                        <input type="checkbox" class="match-checkbox" value="{{ $match->id }}">
                                                        <div>
                                                            <div class="font-medium">{{ $match->homeTeam->name }} vs {{ $match->awayTeam->name }}</div>
                                                            <div class="text-sm text-gray-500">{{ $match->league->name }} • {{ $match->match_date->format('M j, Y H:i') }}</div>
                                                        </div>
                                                    </div>
                                                    <div class="flex space-x-2">
                                                        <select class="prediction-type text-sm border border-gray-300 rounded px-2 py-1" disabled>
                                                            <option value="result">Match Result (1-3 pts)</option>
                                                            <option value="score">Correct Score (5 pts)</option>
                                                            <option value="goalscorer">First Goalscorer (3 pts)</option>
                                                            <option value="total_goals">Total Goals (2 pts)</option>
                                                        </select>
                                                        <input type="number" class="points-value text-sm border border-gray-300 rounded px-2 py-1 w-16" 
                                                               value="1" min="1" max="10" disabled>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                @else
                                    <div class="text-center py-8 text-gray-500">
                                        <p>No available matches found.</p>
                                        <p class="text-sm mt-2">All eligible matches are already included in this prediction set.</p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Selected New Matches -->
                        <div class="mt-4">
                            <h4 class="font-medium mb-3">Selected New Matches (<span id="selectedCount">0</span>)</h4>
                            <div id="selectedMatches" class="space-y-2 min-h-20 border border-gray-200 rounded-lg p-4 bg-gray-50">
                                <p class="text-gray-500 text-center">No new matches selected</p>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end space-x-4">
                        <a href="{{ route('admin.predictions.index') }}" 
                           class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                            Cancel
                        </a>
                        <button type="submit" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Update Prediction Set
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div id="loadingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4">
        <div class="flex items-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <div class="ml-3 text-lg font-medium text-gray-900">Updating prediction set...</div>
        </div>
    </div>
</div>

<script>
let selectedMatches = [];

document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    updateSelectedMatches();
});

function getDefaultPointsForType(predictionType) {
    const pointsMap = {
        'result': 1,
        'score': 5,
        'goalscorer': 3,
        'total_goals': 2
    };
    return pointsMap[predictionType] || 1;
}

function initializeEventListeners() {
    // Match selection
    document.querySelectorAll('.match-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const matchItem = this.closest('.match-item');
            const matchId = parseInt(this.value);
            
            if (this.checked) {
                const predictionType = matchItem.querySelector('.prediction-type').value;
                const pointsValue = getDefaultPointsForType(predictionType);
                
                const matchData = {
                    match_id: matchId,
                    prediction_type: predictionType,
                    points_value: pointsValue
                };
                selectedMatches.push(matchData);
                matchItem.querySelector('.prediction-type').disabled = false;
                matchItem.querySelector('.points-value').disabled = false;
                matchItem.querySelector('.points-value').value = pointsValue;
            } else {
                selectedMatches = selectedMatches.filter(m => m.match_id !== matchId);
                matchItem.querySelector('.prediction-type').disabled = true;
                matchItem.querySelector('.points-value').disabled = true;
            }
            
            updateSelectedMatches();
        });
    });

    // Prediction type and points changes
    document.querySelectorAll('.prediction-type, .points-value').forEach(element => {
        element.addEventListener('change', function() {
            const matchItem = this.closest('.match-item');
            const matchId = parseInt(matchItem.querySelector('.match-checkbox').value);
            const matchData = selectedMatches.find(m => m.match_id === matchId);
            
            if (matchData) {
                const newType = matchItem.querySelector('.prediction-type').value;
                matchData.prediction_type = newType;
                
                if (this.classList.contains('prediction-type')) {
                    const newPoints = getDefaultPointsForType(newType);
                    matchItem.querySelector('.points-value').value = newPoints;
                    matchData.points_value = newPoints;
                } else {
                    matchData.points_value = parseInt(matchItem.querySelector('.points-value').value);
                }
                
                updateSelectedMatches();
            }
        });
    });

    // Filters
    document.getElementById('leagueFilter').addEventListener('change', filterMatches);
    document.getElementById('dateFromFilter').addEventListener('change', filterMatches);
    document.getElementById('dateToFilter').addEventListener('change', filterMatches);
    document.getElementById('searchFilter').addEventListener('input', filterMatches);

    // Form submission
    document.getElementById('predictionForm').addEventListener('submit', handleFormSubmit);
}

function updateSelectedMatches() {
    const selectedCount = document.getElementById('selectedCount');
    const selectedMatchesDiv = document.getElementById('selectedMatches');
    
    selectedCount.textContent = selectedMatches.length;
    
    if (selectedMatches.length === 0) {
        selectedMatchesDiv.innerHTML = '<p class="text-gray-500 text-center">No new matches selected</p>';
        return;
    }
    
    selectedMatchesDiv.innerHTML = selectedMatches.map(match => {
        const matchItem = document.querySelector(`[data-match-id="${match.match_id}"]`);
        const homeTeam = matchItem.querySelector('.font-medium').textContent.split(' vs ')[0];
        const awayTeam = matchItem.querySelector('.font-medium').textContent.split(' vs ')[1];
        const league = matchItem.querySelector('.text-sm').textContent.split(' • ')[0];
        
        return `
            <div class="flex items-center justify-between bg-white border border-gray-200 rounded-lg p-3">
                <div>
                    <div class="font-medium">${homeTeam} vs ${awayTeam}</div>
                    <div class="text-sm text-gray-500">${league} • ${match.prediction_type} • ${match.points_value} points</div>
                </div>
                <button type="button" onclick="removeMatch(${match.match_id})" 
                        class="text-red-600 hover:text-red-900 text-sm">Remove</button>
            </div>
        `;
    }).join('');
}

function removeMatch(matchId) {
    selectedMatches = selectedMatches.filter(m => m.match_id !== matchId);
    
    const checkbox = document.querySelector(`input[value="${matchId}"]`);
    if (checkbox) {
        checkbox.checked = false;
        const matchItem = checkbox.closest('.match-item');
        matchItem.querySelector('.prediction-type').disabled = true;
        matchItem.querySelector('.points-value').disabled = true;
    }
    
    updateSelectedMatches();
}

function filterMatches() {
    const leagueFilter = document.getElementById('leagueFilter').value;
    const dateFromFilter = document.getElementById('dateFromFilter').value;
    const dateToFilter = document.getElementById('dateToFilter').value;
    const searchFilter = document.getElementById('searchFilter').value.toLowerCase();
    
    document.querySelectorAll('.match-item').forEach(item => {
        const leagueId = item.dataset.leagueId;
        const matchText = item.textContent.toLowerCase();
        
        let show = true;
        
        if (leagueFilter && leagueId !== leagueFilter) show = false;
        if (searchFilter && !matchText.includes(searchFilter)) show = false;
        
        item.style.display = show ? 'block' : 'none';
    });
}

function handleFormSubmit(e) {
    e.preventDefault();
    
    const formData = {
        name: document.getElementById('name').value,
        description: document.getElementById('description').value,
        prediction_deadline: document.getElementById('prediction_deadline').value,
        new_matches: selectedMatches
    };
    
    showLoading();
    
    fetch('{{ route("admin.predictions.update", $prediction) }}', {
        method: 'PUT',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            alert(data.message);
            window.location.href = '{{ route("admin.predictions.index") }}';
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Error:', error);
        alert('An error occurred while updating the prediction set.');
    });
}

function showLoading() {
    document.getElementById('loadingModal').classList.remove('hidden');
}

function hideLoading() {
    document.getElementById('loadingModal').classList.add('hidden');
}
</script>
@endsection
