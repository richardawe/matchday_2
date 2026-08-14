@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">🎯 Prediction Management</h1>
                        <p class="text-gray-600 mt-2">Manage prediction sets and track user participation</p>
                    </div>
                    <a href="{{ route('admin.predictions.create') }}" 
                       class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg inline-flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        New Prediction Set
                    </a>
                </div>

                @if(session('success'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                        {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                        {{ session('error') }}
                    </div>
                @endif

                <!-- Filters -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="statusFilter" class="w-full border border-gray-300 rounded-md px-3 py-2">
                                <option value="">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="active">Active</option>
                                <option value="closed">Closed</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
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
                    </div>
                </div>

                @if($predictionSets->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matches</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Participants</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Deadline</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($predictionSets as $predictionSet)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">{{ $predictionSet->name }}</div>
                                                @if($predictionSet->description)
                                                    <div class="text-sm text-gray-500">{{ Str::limit($predictionSet->description, 50) }}</div>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                                @if($predictionSet->status === 'active') bg-green-100 text-green-800
                                                @elseif($predictionSet->status === 'draft') bg-yellow-100 text-yellow-800
                                                @elseif($predictionSet->status === 'closed') bg-red-100 text-red-800
                                                @else bg-gray-100 text-gray-800
                                                @endif">
                                                {{ ucfirst($predictionSet->status) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $predictionSet->matches->count() }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $predictionSet->getParticipationCount() }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $predictionSet->prediction_deadline->format('M j, Y H:i') }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $predictionSet->created_at->format('M j, Y') }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="{{ route('admin.predictions.show', $predictionSet) }}" 
                                                   class="text-blue-600 hover:text-blue-900">View</a>
                                                <a href="{{ route('admin.predictions.edit', $predictionSet) }}" 
                                                   class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                                
                                                @if($predictionSet->status === 'draft')
                                                    <button onclick="activatePredictionSet({{ $predictionSet->id }})" 
                                                            class="text-green-600 hover:text-green-900">Activate</button>
                                                @elseif($predictionSet->status === 'active')
                                                    <button onclick="closePredictionSet({{ $predictionSet->id }})" 
                                                            class="text-red-600 hover:text-red-900">Close</button>
                                                @endif
                                                
                                                <button onclick="deletePredictionSet({{ $predictionSet->id }})" 
                                                        class="text-red-600 hover:text-red-900">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-6">
                        {{ $predictionSets->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No prediction sets</h3>
                        <p class="mt-1 text-sm text-gray-500">Get started by creating a new prediction set.</p>
                        <div class="mt-6">
                            <a href="{{ route('admin.predictions.create') }}" 
                               class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                New Prediction Set
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div id="loadingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4">
        <div class="flex items-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <div class="ml-3 text-lg font-medium text-gray-900">Processing...</div>
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

function activatePredictionSet(id) {
    if (confirm('Are you sure you want to activate this prediction set?')) {
        showLoading();
        
        fetch(`/admin/predictions/${id}/activate`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
            },
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('An error occurred while activating the prediction set.');
        });
    }
}

function closePredictionSet(id) {
    if (confirm('Are you sure you want to close this prediction set?')) {
        showLoading();
        
        fetch(`/admin/predictions/${id}/close`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
            },
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('An error occurred while closing the prediction set.');
        });
    }
}

function deletePredictionSet(id) {
    if (confirm('Are you sure you want to delete this prediction set? This action cannot be undone.')) {
        showLoading();
        
        fetch(`/admin/predictions/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json',
            },
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('An error occurred while deleting the prediction set.');
        });
    }
}
</script>
@endsection
