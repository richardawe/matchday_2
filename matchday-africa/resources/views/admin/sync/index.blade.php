@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl font-bold">⚡ Data Synchronization</h1>
                    <div class="flex space-x-2">
                        <a href="{{ route('admin.dashboard') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                            ← Dashboard
                        </a>
                        <button onclick="refreshApiStatus()" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                            🔄 Refresh Status
                        </button>
                    </div>
                </div>

                <!-- API Status Bar -->
                <div class="bg-gray-100 rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <span class="font-semibold">API Status:</span>
                            <span class="px-3 py-1 rounded {{ $apiStatus['is_configured'] ? 'bg-green-200 text-green-800' : 'bg-red-200 text-red-800' }}">
                                {{ $apiStatus['is_configured'] ? 'Configured' : 'Not Configured' }}
                            </span>
                            <span class="text-sm">
                                Remaining: <strong>{{ $apiStatus['remaining_requests'] ?? 'Unknown' }}</strong> |
                                Local: <strong>{{ $apiStatus['local_requests_this_minute'] ?? 0 }}</strong>/{{ $apiStatus['max_requests_per_minute'] ?? 10 }}
                            </span>
                        </div>
                        <button onclick="clearAllCache()" class="bg-red-500 hover:bg-red-700 text-white font-bold py-1 px-3 rounded text-sm">
                            🗑️ Clear Cache
                        </button>
                    </div>
                </div>

                <!-- Sync Sections -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    
                    <!-- Leagues Sync -->
                    <div class="bg-blue-50 rounded-lg p-6">
                        <h2 class="text-xl font-semibold mb-4 text-blue-800">🏆 Leagues & Competitions</h2>
                        <p class="text-sm text-gray-600 mb-4">Sync football leagues and competitions from the API.</p>
                        
                        <div class="space-y-3">
                            <button onclick="syncLeagues('featured')" class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                ⭐ Sync Featured Leagues
                            </button>
                            <button onclick="syncLeagues('all')" class="w-full bg-blue-600 hover:bg-blue-800 text-white font-bold py-2 px-4 rounded">
                                🌍 Sync All Leagues
                            </button>
                        </div>
                        
                        <div class="mt-4 text-sm text-gray-600">
                            <p><strong>Featured:</strong> Free tier leagues (Premier League, La Liga, etc.)</p>
                            <p><strong>All:</strong> Every available competition (may hit rate limits)</p>
                        </div>
                    </div>

                    <!-- Matches Sync -->
                    <div class="bg-green-50 rounded-lg p-6">
                        <h2 class="text-xl font-semibold mb-4 text-green-800">⚽ Matches</h2>
                        <p class="text-sm text-gray-600 mb-4">Sync football matches and live scores.</p>
                        
                        <div class="space-y-3">
                            <button onclick="syncMatches('today')" class="w-full bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                📅 Sync Today's Matches
                            </button>
                            
                            <div class="flex space-x-2">
                                <input type="date" id="match-date" class="flex-1 border rounded px-3 py-2" value="{{ date('Y-m-d') }}">
                                <button onclick="syncMatchesByDate()" class="bg-green-600 hover:bg-green-800 text-white font-bold py-2 px-4 rounded">
                                    📆 Sync Date
                                </button>
                            </div>
                            
                            <div class="flex space-x-2">
                                <select id="range-days" class="flex-1 border rounded px-3 py-2">
                                    <option value="3">Last 3 days</option>
                                    <option value="7">Last 7 days</option>
                                    <option value="14">Last 14 days</option>
                                </select>
                                <button onclick="syncMatchesByRange()" class="bg-green-600 hover:bg-green-800 text-white font-bold py-2 px-4 rounded">
                                    📊 Sync Range
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Standings Sync -->
                    <div class="bg-yellow-50 rounded-lg p-6">
                        <h2 class="text-xl font-semibold mb-4 text-yellow-800">📊 League Standings</h2>
                        <p class="text-sm text-gray-600 mb-4">Sync league tables and team positions.</p>
                        
                        <div class="space-y-3">
                            <button onclick="syncStandings('all')" class="w-full bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded">
                                🏆 Sync All League Standings
                            </button>
                            
                            <div class="flex space-x-2">
                                <select id="standings-league" class="flex-1 border rounded px-3 py-2">
                                    <option value="">Select League</option>
                                    @foreach($leagues as $league)
                                        <option value="{{ $league->id }}">{{ $league->name }}</option>
                                    @endforeach
                                </select>
                                <button onclick="syncStandingsByLeague()" class="bg-yellow-600 hover:bg-yellow-800 text-white font-bold py-2 px-4 rounded">
                                    📈 Sync League
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Players Sync -->
                    <div class="bg-purple-50 rounded-lg p-6">
                        <h2 class="text-xl font-semibold mb-4 text-purple-800">👥 Player Squads</h2>
                        <p class="text-sm text-gray-600 mb-4">Sync team squads and player information.</p>
                        
                        <div class="space-y-3">
                            <div class="flex space-x-2">
                                <select id="players-team" class="flex-1 border rounded px-3 py-2">
                                    <option value="">Select Team</option>
                                    @foreach($teams as $team)
                                        <option value="{{ $team->id }}">{{ $team->name }} ({{ $team->league->name ?? 'N/A' }})</option>
                                    @endforeach
                                </select>
                                <button onclick="syncPlayersByTeam()" class="bg-purple-600 hover:bg-purple-800 text-white font-bold py-2 px-4 rounded">
                                    👥 Sync Team
                                </button>
                            </div>
                            
                            <div class="flex space-x-2">
                                <select id="players-league" class="flex-1 border rounded px-3 py-2">
                                    <option value="">Select League</option>
                                    @foreach($leagues as $league)
                                        <option value="{{ $league->id }}">{{ $league->name }}</option>
                                    @endforeach
                                </select>
                                <button onclick="syncPlayersByLeague()" class="bg-purple-600 hover:bg-purple-800 text-white font-bold py-2 px-4 rounded">
                                    🏆 Sync League Teams
                                </button>
                            </div>
                            
                            <div class="flex space-x-2">
                                <input type="number" id="players-limit" placeholder="Limit (e.g. 10)" class="flex-1 border rounded px-3 py-2" value="10" min="1" max="50">
                                <button onclick="syncPlayersAll()" class="bg-purple-700 hover:bg-purple-900 text-white font-bold py-2 px-4 rounded">
                                    🌍 Sync All (Limited)
                                </button>
                            </div>
                        </div>
                        
                        <div class="mt-4 text-xs text-gray-600">
                            ⚠️ Player sync is rate-limited. Large operations may take several minutes.
                        </div>
                    </div>
                </div>

                <!-- Progress Section -->
                <div id="progress-section" class="mt-6 hidden">
                    <div class="bg-gray-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4">📊 Sync Progress</h3>
                        <div id="progress-content" class="space-y-2">
                            <!-- Progress will be inserted here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div id="loadingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg max-w-md w-full mx-4">
        <div class="flex items-center mb-4">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <span class="ml-3 text-lg">Synchronizing Data...</span>
        </div>
        <div id="loading-message" class="text-sm text-gray-600">
            This may take a few minutes due to API rate limiting.
        </div>
        <div class="mt-4">
            <button onclick="hideLoading()" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                Run in Background
            </button>
        </div>
    </div>
</div>

<script>
let syncInProgress = false;

function showLoading(message = 'This may take a few minutes due to API rate limiting.') {
    document.getElementById('loadingModal').classList.remove('hidden');
    document.getElementById('loading-message').textContent = message;
    syncInProgress = true;
}

function hideLoading() {
    document.getElementById('loadingModal').classList.add('hidden');
    syncInProgress = false;
}

function showMessage(message, type = 'success') {
    const alertClass = type === 'success' ? 'bg-green-500' : 'bg-red-500';
    const alert = document.createElement('div');
    alert.className = `fixed top-4 right-4 ${alertClass} text-white px-6 py-3 rounded shadow-lg z-50`;
    alert.textContent = message;
    document.body.appendChild(alert);
    
    setTimeout(() => {
        alert.remove();
    }, 5000);
}

function showProgress(message) {
    const progressSection = document.getElementById('progress-section');
    const progressContent = document.getElementById('progress-content');
    
    progressSection.classList.remove('hidden');
    
    const progressItem = document.createElement('div');
    progressItem.className = 'text-sm text-gray-700 border-l-4 border-blue-500 pl-4 py-1';
    progressItem.innerHTML = `<span class="text-xs text-gray-500">${new Date().toLocaleTimeString()}</span> ${message}`;
    
    progressContent.appendChild(progressItem);
    progressContent.scrollTop = progressContent.scrollHeight;
}

// Sync Functions
function syncLeagues(type) {
    if (syncInProgress) return;
    
    showLoading(`Syncing ${type} leagues...`);
    showProgress(`Starting ${type} leagues sync...`);
    
    fetch('{{ route("admin.sync.leagues") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: type })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing leagues', 'error');
        showProgress(`❌ Error syncing leagues: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncMatches(type) {
    if (syncInProgress) return;
    
    showLoading(`Syncing ${type} matches...`);
    showProgress(`Starting ${type} matches sync...`);
    
    fetch('{{ route("admin.sync.matches") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: type })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing matches', 'error');
        showProgress(`❌ Error syncing matches: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncMatchesByDate() {
    const date = document.getElementById('match-date').value;
    if (!date) {
        showMessage('Please select a date', 'error');
        return;
    }
    
    if (syncInProgress) return;
    
    showLoading(`Syncing matches for ${date}...`);
    showProgress(`Starting matches sync for ${date}...`);
    
    fetch('{{ route("admin.sync.matches") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: 'date', date: date })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing matches', 'error');
        showProgress(`❌ Error syncing matches: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncMatchesByRange() {
    const days = document.getElementById('range-days').value;
    
    if (syncInProgress) return;
    
    showLoading(`Syncing matches for last ${days} days...`);
    showProgress(`Starting matches sync for last ${days} days...`);
    
    fetch('{{ route("admin.sync.matches") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: 'range', days: parseInt(days) })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing matches', 'error');
        showProgress(`❌ Error syncing matches: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncStandings(type) {
    if (syncInProgress) return;
    
    showLoading('Syncing standings...');
    showProgress('Starting standings sync...');
    
    fetch('{{ route("admin.sync.standings") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: type })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing standings', 'error');
        showProgress(`❌ Error syncing standings: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncStandingsByLeague() {
    const leagueId = document.getElementById('standings-league').value;
    if (!leagueId) {
        showMessage('Please select a league', 'error');
        return;
    }
    
    if (syncInProgress) return;
    
    showLoading('Syncing league standings...');
    showProgress(`Starting standings sync for league ${leagueId}...`);
    
    fetch('{{ route("admin.sync.standings") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: 'league', league_id: parseInt(leagueId) })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing standings', 'error');
        showProgress(`❌ Error syncing standings: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncPlayersByTeam() {
    const teamId = document.getElementById('players-team').value;
    if (!teamId) {
        showMessage('Please select a team', 'error');
        return;
    }
    
    if (syncInProgress) return;
    
    showLoading('Syncing team players...');
    showProgress(`Starting players sync for team ${teamId}...`);
    
    fetch('{{ route("admin.sync.players") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: 'team', team_id: parseInt(teamId) })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing players', 'error');
        showProgress(`❌ Error syncing players: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncPlayersByLeague() {
    const leagueId = document.getElementById('players-league').value;
    const limit = document.getElementById('players-limit').value || 10;
    
    if (!leagueId) {
        showMessage('Please select a league', 'error');
        return;
    }
    
    if (syncInProgress) return;
    
    showLoading('Syncing league players...');
    showProgress(`Starting players sync for league ${leagueId} (limit: ${limit})...`);
    
    fetch('{{ route("admin.sync.players") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: 'league', league_id: parseInt(leagueId), limit: parseInt(limit) })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing players', 'error');
        showProgress(`❌ Error syncing players: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function syncPlayersAll() {
    const limit = document.getElementById('players-limit').value || 10;
    
    if (!confirm(`This will sync players for ${limit} teams. This may take a very long time. Continue?`)) {
        return;
    }
    
    if (syncInProgress) return;
    
    showLoading('Syncing all players...');
    showProgress(`Starting players sync for all teams (limit: ${limit})...`);
    
    fetch('{{ route("admin.sync.players") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: 'all', limit: parseInt(limit) })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error syncing players', 'error');
        showProgress(`❌ Error syncing players: ${error.message}`);
    })
    .finally(() => {
        hideLoading();
    });
}

function clearAllCache() {
    if (!confirm('Are you sure you want to clear all football data caches?')) return;
    
    fetch('{{ route("admin.cache.clear") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            showProgress(`✅ ${data.message}`);
        } else {
            showMessage(data.message, 'error');
            showProgress(`❌ ${data.message}`);
        }
    })
    .catch(error => {
        showMessage('Error clearing cache', 'error');
        showProgress(`❌ Error clearing cache: ${error.message}`);
    });
}

function refreshApiStatus() {
    fetch('{{ route("admin.api.status") }}')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                showMessage('Failed to refresh API status', 'error');
            }
        })
        .catch(error => {
            showMessage('Error refreshing API status', 'error');
        });
}
</script>
@endsection