@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Match Previews Management</h1>
                        <p class="text-gray-600 mt-2">Generate and manage AI-powered match previews</p>
                    </div>
                    <div class="flex space-x-3">
                        <button onclick="generateDailyPreviews()" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Generate Daily Previews
                        </button>
                        <button onclick="forceRegenerateAll()" 
                                class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                            🔄 Force Regenerate All
                        </button>
                        <button onclick="refreshStats()" 
                                class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                            Refresh Stats
                        </button>
                    </div>
                </div>

                <!-- Date Filter -->
                <div class="mb-6">
                    <form method="GET" class="flex items-center space-x-4">
                        <label for="date" class="text-sm font-medium text-gray-700">Select Date:</label>
                        <input type="date" 
                               id="date" 
                               name="date" 
                               value="{{ $selectedDate->format('Y-m-d') }}"
                               class="border border-gray-300 rounded-md px-3 py-2">
                        <button type="submit" 
                                class="bg-indigo-500 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">
                            Filter
                        </button>
                    </form>
                </div>

                <!-- Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                    <div class="bg-blue-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-blue-600">Total Previews</div>
                        <div class="text-2xl font-bold text-blue-900">{{ $stats['total_previews'] }}</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-green-600">Featured</div>
                        <div class="text-2xl font-bold text-green-900">{{ $stats['featured_previews'] }}</div>
                    </div>
                    <div class="bg-yellow-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-yellow-600">Recent (7 days)</div>
                        <div class="text-2xl font-bold text-yellow-900">{{ $stats['recent_previews'] }}</div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-4">
                        <div class="text-sm font-medium text-purple-600">API Usage</div>
                        <div class="text-2xl font-bold text-purple-900">{{ $stats['api_usage']['daily_requests'] }}/{{ $stats['api_usage']['max_daily_requests'] }}</div>
                    </div>
                </div>

                <!-- Previews Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">League</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Generated</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($previews as $preview)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $preview->match->homeTeam->name }} vs {{ $preview->match->awayTeam->name }}
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $preview->match->league->name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $preview->match->match_date->format('M j, Y H:i') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            {{ $preview->generation_status === 'completed' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                            {{ ucfirst($preview->generation_status) }}
                                        </span>
                                        @if($preview->is_featured)
                                            <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                Featured
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $preview->generated_at ? $preview->generated_at->diffForHumans() : 'Not generated' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="regeneratePreview({{ $preview->match->id }})" 
                                                    class="text-indigo-600 hover:text-indigo-900">
                                                Regenerate
                                            </button>
                                            <button onclick="toggleFeatured({{ $preview->id }})" 
                                                    class="text-blue-600 hover:text-blue-900">
                                                {{ $preview->is_featured ? 'Unfeature' : 'Feature' }}
                                            </button>
                                            <button onclick="deletePreview({{ $preview->id }})" 
                                                    class="text-red-600 hover:text-red-900">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No previews found for {{ $selectedDate->format('M j, Y') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="mt-6">
                    {{ $previews->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Generate Daily Previews Modal -->
<div id="generateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Generate Daily Previews</h3>
                <div class="mb-4">
                    <label for="generateDate" class="block text-sm font-medium text-gray-700">Date:</label>
                    <input type="date" id="generateDate" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" id="forceRegenerate" class="rounded border-gray-300 text-red-600 shadow-sm focus:ring-red-500">
                        <span class="ml-2 text-sm font-medium text-red-700">⚠️ Force regeneration of existing previews</span>
                    </label>
                    <p class="mt-1 text-xs text-red-600">This will delete and regenerate all existing previews for the selected date.</p>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button onclick="confirmGenerate()" 
                        class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                    Generate
                </button>
                <button onclick="closeGenerateModal()" 
                        class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function generateDailyPreviews() {
    document.getElementById('generateDate').value = '{{ $selectedDate->format('Y-m-d') }}';
    document.getElementById('generateModal').classList.remove('hidden');
}

function closeGenerateModal() {
    document.getElementById('generateModal').classList.add('hidden');
}

function confirmGenerate() {
    const date = document.getElementById('generateDate').value;
    const force = document.getElementById('forceRegenerate').checked;
    
    fetch('{{ route('admin.match-previews.generate-daily') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({
            date: date,
            force: force
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
        alert('An error occurred while generating previews');
    });
    
    closeGenerateModal();
}

function regeneratePreview(matchId) {
    if (confirm('Are you sure you want to regenerate this preview?')) {
        fetch(`{{ url('admin/match-previews/regenerate') }}/${matchId}`, {
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
            alert('An error occurred while regenerating preview');
        });
    }
}

function toggleFeatured(previewId) {
    fetch(`{{ url('admin/match-previews/toggle-featured') }}/${previewId}`, {
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
        alert('An error occurred while updating featured status');
    });
}

function deletePreview(previewId) {
    if (confirm('Are you sure you want to delete this preview?')) {
        fetch(`{{ url('admin/match-previews') }}/${previewId}`, {
            method: 'DELETE',
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
            alert('An error occurred while deleting preview');
        });
    }
}

function refreshStats() {
    location.reload();
}

function forceRegenerateAll() {
    if (confirm('⚠️ WARNING: This will delete and regenerate ALL previews for {{ $selectedDate->format("M j, Y") }}. This action cannot be undone. Are you sure you want to continue?')) {
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '🔄 Regenerating...';
        button.disabled = true;
        
        fetch('{{ route("admin.match-previews.force-regenerate-all") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                date: '{{ $selectedDate->format("Y-m-d") }}'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`✅ ${data.message}\n\nResults:\n- Successfully regenerated: ${data.results.success}\n- Failed: ${data.results.failed}\n- Total processed: ${data.results.total}`);
                location.reload();
            } else {
                alert('❌ Error: ' + data.message);
                button.innerHTML = originalText;
                button.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ An error occurred while force regenerating previews');
            button.innerHTML = originalText;
            button.disabled = false;
        });
    }
}
</script>
@endsection
