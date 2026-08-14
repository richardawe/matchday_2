@extends('layouts.admin')

@section('title', 'Match Management')

@section('header')
<div class="flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">⚽ Match Management</h1>
        <p class="text-gray-600 mt-2">Manage match scores and trigger prediction scoring</p>
    </div>
    <div class="flex space-x-3">
        <button onclick="autoUpdateScores()" 
                class="bg-orange-500 hover:bg-orange-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
            🎯 Auto Update Scores
        </button>
        <button onclick="verifyAllScores()" 
                class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
            ✅ Verify All Scores
        </button>
    </div>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" id="stats-cards">
        <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-6 border border-blue-200">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center mr-4">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-blue-600">Total Matches</p>
                    <p class="text-2xl font-bold text-blue-900" id="total-matches">{{ $matches->total() }}</p>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center mr-4">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-green-600">Finished</p>
                    <p class="text-2xl font-bold text-green-900" id="finished-matches">{{ $matches->where('status', 'FINISHED')->count() }}</p>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-lg p-6 border border-yellow-200">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-yellow-500 rounded-lg flex items-center justify-center mr-4">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-yellow-600">Scheduled</p>
                    <p class="text-2xl font-bold text-yellow-900" id="scheduled-matches">{{ $matches->where('status', 'SCHEDULED')->count() }}</p>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-r from-red-50 to-red-100 rounded-lg p-6 border border-red-200">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-red-500 rounded-lg flex items-center justify-center mr-4">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.293l-3-3a1 1 0 00-1.414 0l-3 3a1 1 0 001.414 1.414L9 9.414V13a1 1 0 102 0V9.414l1.293 1.293a1 1 0 001.414-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-red-600">Live</p>
                    <p class="text-2xl font-bold text-red-900" id="live-matches">{{ $matches->where('status', 'LIVE')->count() }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <form method="GET" action="{{ route('admin.matches.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" {{ request('status') == $status ? 'selected' : '' }}>
                            {{ ucfirst($status) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">League</label>
                <select name="league_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Leagues</option>
                    @foreach($leagues as $league)
                        <option value="{{ $league->id }}" {{ request('league_id') == $league->id ? 'selected' : '' }}>
                            {{ $league->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                <input type="date" name="date_from" value="{{ request('date_from', $defaultDateFrom) }}" 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                <input type="date" name="date_to" value="{{ request('date_to', $defaultDateTo) }}" 
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                    🔍 Filter
                </button>
                <a href="{{ route('admin.matches.index', ['date_from' => '', 'date_to' => '']) }}" 
                   class="flex-1 bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg text-center">
                    📅 Show All
                </a>
            </div>
        </form>
    </div>

    <!-- Matches Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">📋 Matches</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <input type="checkbox" id="select-all" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">League</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($matches as $match)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <input type="checkbox" class="match-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500" 
                                       value="{{ $match->id }}">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <img class="h-10 w-10 rounded-full" 
                                             src="{{ $match->homeTeam->logo_url ?? asset('images/default-team.png') }}" 
                                             alt="{{ $match->homeTeam->name }}">
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ $match->homeTeam->name }}</div>
                                        <div class="text-sm text-gray-500">vs {{ $match->awayTeam->name }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ $match->league->name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ $match->match_date->format('M j, Y') }}</div>
                                <div class="text-sm text-gray-500">{{ $match->match_date->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($match->home_score !== null && $match->away_score !== null)
                                    <div class="text-lg font-bold text-gray-900">
                                        {{ $match->home_score }} - {{ $match->away_score }}
                                    </div>
                                @else
                                    <div class="text-sm text-gray-500">-</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 rounded-full text-xs font-medium
                                    @if(strtolower($match->status) === 'finished') bg-green-100 text-green-800
                                    @elseif(strtolower($match->status) === 'scheduled') bg-yellow-100 text-yellow-800
                                    @elseif(strtolower($match->status) === 'live') bg-red-100 text-red-800
                                    @elseif(strtolower($match->status) === 'cancelled') bg-gray-100 text-gray-800
                                    @else bg-blue-100 text-blue-800
                                    @endif">
                                    {{ ucfirst(strtolower($match->status)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2">
                                    <a href="{{ route('admin.matches.show', $match) }}" 
                                       class="text-blue-600 hover:text-blue-900">View</a>
                                    <button onclick="updateScoreModal({{ $match->id }}, '{{ $match->homeTeam->name }}', '{{ $match->awayTeam->name }}', {{ $match->home_score ?? 'null' }}, {{ $match->away_score ?? 'null' }}, '{{ $match->status }}')" 
                                            class="text-green-600 hover:text-green-900">Update Score</button>
                                    @if(strtolower($match->status) === 'finished')
                                        <button onclick="forceScore({{ $match->id }})" 
                                                class="text-purple-600 hover:text-purple-900">Force Score</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <div class="text-gray-500">
                                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">No matches found</h3>
                                    <p class="mt-1 text-sm text-gray-500">Try adjusting your filters or check back later.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($matches->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $matches->links() }}
            </div>
        @endif
    </div>
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
                <input type="hidden" id="match_id" name="match_id">
                
                <div class="mb-4">
                    <div class="text-center text-lg font-semibold text-gray-900" id="match_teams"></div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Home Score</label>
                        <input type="number" id="home_score" name="home_score" min="0" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Away Score</label>
                        <input type="number" id="away_score" name="away_score" min="0" 
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select id="match_status" name="status" 
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="scheduled">Scheduled</option>
                        <option value="live">Live</option>
                        <option value="finished">Finished</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="postponed">Postponed</option>
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

<!-- Bulk Update Modal -->
<div id="bulkUpdateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">Bulk Update Matches</h3>
                <button onclick="closeBulkUpdateModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div class="mb-4">
                <p class="text-sm text-gray-600">Selected matches: <span id="selected-count">0</span></p>
            </div>
            
            <form id="bulkUpdateForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select id="bulk_status" name="status" 
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="scheduled">Scheduled</option>
                            <option value="live">Live</option>
                            <option value="finished">Finished</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="postponed">Postponed</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Force Scoring</label>
                        <input type="checkbox" id="force_scoring" name="force_scoring" 
                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-sm text-gray-600">Score predictions for finished matches</span>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeBulkUpdateModal()" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 text-sm font-medium text-white bg-purple-600 hover:bg-purple-700 rounded-lg">
                        Update Selected
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Update Score Modal Functions
function updateScoreModal(matchId, homeTeam, awayTeam, homeScore, awayScore, status) {
    document.getElementById('match_id').value = matchId;
    document.getElementById('match_teams').textContent = `${homeTeam} vs ${awayTeam}`;
    document.getElementById('home_score').value = homeScore || 0;
    document.getElementById('away_score').value = awayScore || 0;
    document.getElementById('match_status').value = status;
    document.getElementById('updateScoreModal').classList.remove('hidden');
}

function closeUpdateScoreModal() {
    document.getElementById('updateScoreModal').classList.add('hidden');
}

// Bulk Update Modal Functions
function bulkUpdateModal() {
    const selectedMatches = getSelectedMatches();
    if (selectedMatches.length === 0) {
        alert('Please select at least one match');
        return;
    }
    document.getElementById('selected-count').textContent = selectedMatches.length;
    document.getElementById('bulkUpdateModal').classList.remove('hidden');
}

function closeBulkUpdateModal() {
    document.getElementById('bulkUpdateModal').classList.add('hidden');
}

// Checkbox Functions
function getSelectedMatches() {
    const checkboxes = document.querySelectorAll('.match-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.match-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Form Submissions
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

document.getElementById('bulkUpdateForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const selectedMatches = getSelectedMatches();
    if (selectedMatches.length === 0) {
        alert('Please select at least one match');
        return;
    }
    
    const formData = new FormData(this);
    
    fetch('/admin/matches/bulk-update-status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            match_ids: selectedMatches,
            status: formData.get('status'),
            force_scoring: formData.get('force_scoring') === 'on'
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
        alert('An error occurred while updating matches');
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
function refreshMatches() {
    location.reload();
}

// Auto Update Scores Function
function autoUpdateScores() {
    if (confirm('This will automatically update scores for all finished matches. Are you sure?')) {
        fetch('/admin/matches/auto-update-scores', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                update_all_finished: true
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                if (data.matches && data.matches.length > 0) {
                    console.log('Updated matches:', data.matches);
                }
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while auto-updating scores');
        });
    }
}

// Verify All Scores Function
function verifyAllScores() {
    if (confirm('This will verify match scores against the API and correct any discrepancies. Due to API rate limits, this will check 10 matches at a time and may take several minutes. Continue?')) {
        fetch('/admin/matches/verify-all-scores', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                limit: 10,
                status: 'all'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let message = data.message;
                if (data.corrections && data.corrections.length > 0) {
                    message += '\n\nCorrections made:\n';
                    data.corrections.forEach(correction => {
                        message += `• ${correction.teams}: ${correction.old_score} → ${correction.new_score}\n`;
                    });
                }
                if (data.errors && data.errors.length > 0) {
                    message += '\n\nErrors encountered:\n';
                    data.errors.slice(0, 5).forEach(error => {
                        message += `• ${error}\n`;
                    });
                    if (data.errors.length > 5) {
                        message += `• ... and ${data.errors.length - 5} more errors\n`;
                    }
                }
                alert(message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while verifying scores');
        });
    }
}
</script>
@endsection
