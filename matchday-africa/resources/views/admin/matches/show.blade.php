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

    <section class="bg-white rounded-xl border border-gray-200 p-6">
        <p class="text-xs font-bold tracking-widest text-purple-700">MATCH SPONSOR & PRIZE</p><h3 class="text-xl font-semibold mt-1">Reward prediction winners</h3><p class="text-sm text-gray-500 mb-5">If exact-score winners exceed the prize quantity, earliest submissions receive the prizes.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('admin.matches.sponsor.store',$match) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">@csrf
            <label class="text-sm font-medium">Sponsor name<input name="name" required value="{{ old('name',$match->sponsor?->name) }}" class="mt-1 w-full border-gray-300 rounded-lg"></label>
            <label class="text-sm font-medium">Sponsor website<input name="website_url" type="url" value="{{ old('website_url',$match->sponsor?->website_url) }}" class="mt-1 w-full border-gray-300 rounded-lg"></label>
            <label class="text-sm font-medium">Prize<input name="prize_title" required value="{{ old('prize_title',$match->sponsor?->prize_title) }}" placeholder="Signed shirt" class="mt-1 w-full border-gray-300 rounded-lg"></label>
            <label class="text-sm font-medium">Prize value<input name="prize_value" value="{{ old('prize_value',$match->sponsor?->prize_value) }}" placeholder="£100" class="mt-1 w-full border-gray-300 rounded-lg"></label>
            <label class="text-sm font-medium">Number of prizes<input name="prize_quantity" required type="number" min="1" max="100" value="{{ old('prize_quantity',$match->sponsor?->prize_quantity??1) }}" class="mt-1 w-full border-gray-300 rounded-lg"></label>
            <label class="text-sm font-medium">Claim deadline<input name="claim_deadline" type="datetime-local" value="{{ old('claim_deadline',$match->sponsor?->claim_deadline?->format('Y-m-d\TH:i')) }}" class="mt-1 w-full border-gray-300 rounded-lg"></label>
            <label class="text-sm font-medium">Logo<input name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 w-full border border-gray-300 rounded-lg p-2"></label>
            <label class="text-sm font-medium flex items-center gap-2 pt-7"><input name="active" type="checkbox" value="1" {{ old('active',$match->sponsor?->active??true)?'checked':'' }} class="rounded"> Show sponsor and prize</label>
            <label class="text-sm font-medium md:col-span-2">Terms<textarea name="terms" maxlength="2000" rows="3" class="mt-1 w-full border-gray-300 rounded-lg">{{ old('terms',$match->sponsor?->terms) }}</textarea></label>
            <button class="md:col-span-2 bg-purple-700 hover:bg-purple-800 text-white font-bold py-3 rounded-lg">Save sponsor and prize</button>
        </form>
        @if($match->sponsor)<div class="mt-6 border-t pt-5"><div class="flex justify-between"><h4 class="font-bold">Prize winners</h4><form method="POST" action="{{ route('admin.matches.sponsor.destroy',$match) }}" onsubmit="return confirm('Remove sponsor and prize?')">@csrf @method('DELETE')<button class="text-red-700 text-sm">Remove sponsor</button></form></div><div class="mt-3 space-y-2">@forelse($match->sponsor->awards as $award)<form method="POST" action="{{ route('admin.matches.awards.update',[$match,$award]) }}" class="flex items-center gap-3 border rounded-lg p-3">@csrf @method('PATCH')<strong class="flex-1">{{ $award->user?->name }}</strong><select name="status" class="rounded-lg border-gray-300"><option value="selected" @selected($award->status==='selected')>Selected</option><option value="contacted" @selected($award->status==='contacted')>Contacted</option><option value="claimed" @selected($award->status==='claimed')>Claimed</option><option value="fulfilled" @selected($award->status==='fulfilled')>Fulfilled</option></select><button class="text-purple-700 font-bold">Update</button></form>@empty<p class="text-sm text-gray-500">Winners appear after the match is scored.</p>@endforelse</div></div>@endif
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="bg-white rounded-xl border border-gray-200 p-6">
            <p class="text-xs font-bold tracking-widest text-red-700">LIVE REPORTER</p>
            <h3 class="text-xl font-semibold text-gray-900 mt-1 mb-1">Publish a verified event</h3>
            <p class="text-sm text-gray-500 mb-5">Use an official club or competition link as evidence.</p>
            <form method="POST" action="{{ route('admin.matches.events.store', $match) }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <label class="text-sm font-medium text-gray-700">Team
                        <select name="team_id" required class="mt-1 w-full border-gray-300 rounded-lg">
                            <option value="{{ $match->home_team_id }}">{{ $match->homeTeam->name }}</option>
                            <option value="{{ $match->away_team_id }}">{{ $match->awayTeam->name }}</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-gray-700">Event
                        <select name="type" required class="mt-1 w-full border-gray-300 rounded-lg">
                            <option value="goal">Goal</option><option value="yellow_card">Yellow card</option>
                            <option value="red_card">Red card</option><option value="substitution">Substitution</option><option value="var">VAR</option>
                        </select>
                    </label>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <label class="text-sm font-medium text-gray-700">Minute<input name="minute" type="number" min="0" max="130" required class="mt-1 w-full border-gray-300 rounded-lg"></label>
                    <label class="text-sm font-medium text-gray-700">Added<input name="extra_minute" type="number" min="0" max="30" class="mt-1 w-full border-gray-300 rounded-lg"></label>
                    <label class="text-sm font-medium text-gray-700 flex items-end gap-2 pb-2"><input name="is_penalty" type="checkbox" value="1" class="rounded"> Penalty</label>
                </div>
                <label class="block text-sm font-medium text-gray-700">Player<input name="player_name" maxlength="150" class="mt-1 w-full border-gray-300 rounded-lg" placeholder="Scorer, booked player or player on"></label>
                <label class="block text-sm font-medium text-gray-700">Assist / player off<input name="assist_player_name" maxlength="150" class="mt-1 w-full border-gray-300 rounded-lg"></label>
                <label class="block text-sm font-medium text-gray-700">Source link<input name="source_url" type="url" required class="mt-1 w-full border-gray-300 rounded-lg" placeholder="https://official-club-site.example/update"></label>
                <label class="block text-sm font-medium text-gray-700">Note<textarea name="description" maxlength="500" rows="2" class="mt-1 w-full border-gray-300 rounded-lg"></textarea></label>
                <button class="w-full bg-red-700 hover:bg-red-800 text-white font-bold py-3 px-4 rounded-lg">Publish verified event</button>
            </form>
        </section>
        <section class="bg-white rounded-xl border border-gray-200 p-6">
            <h3 class="text-xl font-semibold text-gray-900 mb-4">Event timeline</h3>
            <div class="space-y-3">
                @forelse($match->events->sortBy([['minute','desc'],['sort_order','desc']]) as $event)
                    <div class="border border-gray-200 rounded-lg p-3 flex gap-3 items-start">
                        <strong class="text-lg text-gray-900">{{ $event->minute }}{{ $event->extra_minute ? "+{$event->extra_minute}" : '' }}′</strong>
                        <div class="flex-1"><b>{{ Str::headline($event->type) }}</b><p class="text-sm text-gray-600">{{ $event->player_name ?: $event->team?->name }}{{ $event->assist_player_name ? ' · '.$event->assist_player_name : '' }}</p>
                            <small class="text-gray-500">{{ data_get($event->metadata,'provider','provider') }}</small>
                            @if(data_get($event->metadata,'source_url')) · <a class="text-blue-700 text-xs" target="_blank" rel="noopener noreferrer" href="{{ data_get($event->metadata,'source_url') }}">source ↗</a>@endif
                        </div>
                        @if(data_get($event->metadata,'provider')==='matchday-reporter')
                            <form method="POST" action="{{ route('admin.matches.events.destroy',[$match,$event]) }}" onsubmit="return confirm('Remove this event?')">@csrf @method('DELETE')<button class="text-xs text-red-700">Remove</button></form>
                        @endif
                    </div>
                @empty <p class="text-sm text-gray-500">No events published yet.</p> @endforelse
            </div>
        </section>
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
