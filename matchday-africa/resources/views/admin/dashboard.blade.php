@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('header')
<div class="flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">🛠️ Admin Dashboard</h1>
        <p class="text-gray-600 mt-2">Manage your Matchday Africa platform</p>
    </div>
@endsection

@section('content')
<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                <!-- Admin Navigation -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">🛠️ Admin Panel</h2>
                    
                    <!-- Content Management Section -->
                    <div class="mb-8">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            📝 Content Management
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <a href="{{ route('admin.creators.index') }}" class="bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-lg p-4 transition-colors"><div class="flex items-center"><div class="w-10 h-10 bg-amber-600 rounded-lg flex items-center justify-center mr-3"><span class="text-white text-lg">✍</span></div><div><h4 class="font-semibold text-gray-900">Creator Council</h4><p class="text-sm text-gray-600">Applications & story review</p></div></div></a>
                            <a href="{{ route('admin.blogs.index') }}" class="bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">📰</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Blog Management</h4>
                                        <p class="text-sm text-gray-600">Manage blog posts</p>
                                    </div>
                                </div>
                            </a>
                            
                            <a href="{{ route('admin.match-previews.index') }}" class="bg-purple-50 hover:bg-purple-100 border border-purple-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">🔮</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Match Previews</h4>
                                        <p class="text-sm text-gray-600">AI-generated match previews</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Match Management Section -->
                    <div class="mb-8">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            ⚽ Match Management
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <a href="{{ route('admin.matches.index') }}" class="bg-green-50 hover:bg-green-100 border border-green-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">⚽</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Match Management</h4>
                                        <p class="text-sm text-gray-600">Manage matches & scores</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Prediction System Section -->
                    <div class="mb-8">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            🎯 Prediction System
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <a href="{{ route('admin.predictions.index') }}" class="bg-purple-50 hover:bg-purple-100 border border-purple-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">🎯</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Prediction Sets</h4>
                                        <p class="text-sm text-gray-600">Manage prediction sets</p>
                                    </div>
                                </div>
                            </a>
                            
                            <a href="{{ route('admin.predictions.create') }}" class="bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-indigo-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">➕</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Create Prediction Set</h4>
                                        <p class="text-sm text-gray-600">Create new prediction set</p>
                                    </div>
                                </div>
                            </a>
                            
                            <a href="{{ route('admin.predictions.analytics') }}" class="bg-orange-50 hover:bg-orange-100 border border-orange-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">📊</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Prediction Analytics</h4>
                                        <p class="text-sm text-gray-600">View analytics dashboard</p>
                                    </div>
                                </div>
                            </a>
                            
                            <a href="{{ route('admin.predictions.transparency') }}" class="bg-teal-50 hover:bg-teal-100 border border-teal-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-teal-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">🔍</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Prediction Transparency</h4>
                                        <p class="text-sm text-gray-600">View prediction transparency</p>
                                    </div>
                                </div>
                            </a>

                            <a href="{{ route('admin.predictions.season.index') }}" class="bg-red-50 hover:bg-red-100 border border-red-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-red-600 rounded-lg flex items-center justify-center mr-3"><span class="text-white text-lg">↻</span></div>
                                    <div><h4 class="font-semibold text-gray-900">Start New Season</h4><p class="text-sm text-gray-600">Archive challenges and reset predictions</p></div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Data Management Section -->
                    <div class="mb-8">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            🔄 Data Management
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <a href="{{ route('admin.sync.index') }}" class="bg-cyan-50 hover:bg-cyan-100 border border-cyan-200 rounded-lg p-4 transition-colors">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-cyan-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">🔄</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Data Synchronization</h4>
                                        <p class="text-sm text-gray-600">Sync data from API</p>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- System Management Section -->
                    <div class="mb-8">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            🛠️ System Management
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <button onclick="clearCache()" class="bg-red-50 hover:bg-red-100 border border-red-200 rounded-lg p-4 transition-colors text-left">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-red-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">🗑️</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Clear Cache</h4>
                                        <p class="text-sm text-gray-600">Clear application cache</p>
                                    </div>
                                </div>
                            </button>
                            
                            <button onclick="refreshApiStatus()" class="bg-yellow-50 hover:bg-yellow-100 border border-yellow-200 rounded-lg p-4 transition-colors text-left">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-yellow-500 rounded-lg flex items-center justify-center mr-3">
                                        <span class="text-white text-lg">🔄</span>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-900">Refresh API Status</h4>
                                        <p class="text-sm text-gray-600">Update API status</p>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- API Status Card -->
                <div class="bg-gray-50 rounded-lg p-6 mb-6">
                    <h2 class="text-lg font-semibold mb-4">📡 API Status</h2>
                    <div id="api-status" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold {{ $apiStatus['is_configured'] ? 'text-green-600' : 'text-red-600' }}">
                                {{ $apiStatus['is_configured'] ? '✅' : '❌' }}
                            </div>
                            <div class="text-sm text-gray-600">API Configured</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600">{{ $apiStatus['remaining_requests'] ?? 'N/A' }}</div>
                            <div class="text-sm text-gray-600">Remaining Requests</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-purple-600">{{ $apiStatus['local_requests_this_minute'] ?? 0 }}</div>
                            <div class="text-sm text-gray-600">Local Requests/Min</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-orange-600">{{ $apiStatus['max_requests_per_minute'] ?? 10 }}</div>
                            <div class="text-sm text-gray-600">Rate Limit</div>
                        </div>
                    </div>
                    @if(isset($apiStatus['last_updated']) && $apiStatus['last_updated'])
                        <div class="text-xs text-gray-500 mt-2">
                            Last updated: {{ $apiStatus['last_updated']->diffForHumans() }}
                        </div>
                    @endif
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                    <!-- Leagues Stats -->
                    <div class="bg-blue-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-blue-800">🏆 Leagues</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span>Total:</span>
                                <span class="font-bold">{{ $stats['leagues']['total'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Active:</span>
                                <span class="font-bold text-green-600">{{ $stats['leagues']['active'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Featured:</span>
                                <span class="font-bold text-blue-600">{{ $stats['leagues']['featured'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Teams Stats -->
                    <div class="bg-green-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-green-800">⚽ Teams</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span>Total:</span>
                                <span class="font-bold">{{ $stats['teams']['total'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Active:</span>
                                <span class="font-bold text-green-600">{{ $stats['teams']['active'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Matches Stats -->
                    <div class="bg-purple-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-purple-800">🥅 Matches</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span>Total:</span>
                                <span class="font-bold">{{ $stats['matches']['total'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Today:</span>
                                <span class="font-bold text-blue-600">{{ $stats['matches']['today'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Live:</span>
                                <span class="font-bold text-red-600">{{ $stats['matches']['live'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>This Week:</span>
                                <span class="font-bold text-purple-600">{{ $stats['matches']['this_week'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Standings Stats -->
                    <div class="bg-yellow-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-yellow-800">📊 Standings</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span>Total:</span>
                                <span class="font-bold">{{ $stats['standings']['total'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Current:</span>
                                <span class="font-bold text-green-600">{{ $stats['standings']['current'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Players Stats -->
                    <div class="bg-red-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-red-800">👥 Players</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span>Total:</span>
                                <span class="font-bold">{{ $stats['players']['total'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Active:</span>
                                <span class="font-bold text-green-600">{{ $stats['players']['active'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Match Previews Stats -->
                    <div class="bg-indigo-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-indigo-800">🔮 Match Previews</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span>Total:</span>
                                <span class="font-bold">{{ $stats['previews']['total'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Active:</span>
                                <span class="font-bold text-green-600">{{ $stats['previews']['active'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Featured:</span>
                                <span class="font-bold text-purple-600">{{ $stats['previews']['featured'] }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Today:</span>
                                <span class="font-bold text-blue-600">{{ $stats['previews']['today'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-gray-50 rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-800">⚡ Quick Actions</h3>
                        <div class="space-y-2">
                            <button onclick="clearCache()" class="w-full bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded text-sm">
                                🗑️ Clear Cache
                            </button>
                            <button onclick="syncToday()" class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                                📅 Sync Today
                            </button>
                            <a href="{{ route('admin.sync.index') }}" class="block w-full bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm text-center">
                                ⚙️ Full Sync
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Sync Logs -->
                @if(!empty($recentLogs))
                <div class="bg-gray-50 rounded-lg p-6">
                    <h2 class="text-lg font-semibold mb-4">📝 Recent Sync Logs</h2>
                    <div class="space-y-2 max-h-60 overflow-y-auto">
                        @foreach($recentLogs as $log)
                            <div class="text-xs font-mono bg-white p-2 rounded border">
                                {{ trim($log) }}
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div id="loadingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white p-6 rounded-lg">
        <div class="flex items-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <span class="ml-3 text-lg">Processing...</span>
        </div>
    </div>
</div>

<script>
function showLoading() {
    document.getElementById('loadingModal').classList.remove('hidden');
}

function hideLoading() {
    document.getElementById('loadingModal').classList.add('hidden');
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

function refreshApiStatus() {
    showLoading();
    fetch('{{ route("admin.api.status") }}')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload(); // Simple refresh for now
            } else {
                showMessage('Failed to refresh API status', 'error');
            }
        })
        .catch(error => {
            showMessage('Error refreshing API status', 'error');
        })
        .finally(() => {
            hideLoading();
        });
}

function clearCache() {
    if (!confirm('Are you sure you want to clear all caches?')) return;
    
    showLoading();
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
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        showMessage('Error clearing cache', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}

function syncToday() {
    if (!confirm('Sync today\'s matches?')) return;
    
    showLoading();
    fetch('{{ route("admin.sync.matches") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ type: 'today' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.message, 'error');
        }
    })
    .catch(error => {
        showMessage('Error syncing matches', 'error');
    })
    .finally(() => {
        hideLoading();
    });
}
</script>
@endsection
