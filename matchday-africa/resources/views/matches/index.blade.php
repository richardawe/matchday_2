@extends('layouts.public')

@section('content')
@php
    $matchTotal = method_exists($matches, 'total') ? $matches->total() : $matches->count();
    $canPaginateMatches = method_exists($matches, 'links');
@endphp
<div class="py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">⚽ All Matches</h1>
            <p class="mt-2 text-gray-600">Live Scores, Results & Fixtures</p>
        </div>

        <!-- Quick Filter Buttons -->
        <div class="mb-6">
            <div class="flex flex-wrap gap-2 sm:gap-4">
                <a href="{{ route('matches.index', ['filter' => 'live']) }}" 
                   class="filter-btn {{ $filter == 'live' ? 'active' : '' }}">
                    🔴 Live: {{ $liveCounts }}
                </a>
                <a href="{{ route('matches.index', ['filter' => 'today']) }}" 
                   class="filter-btn {{ $filter == 'today' ? 'active' : '' }}">
                    📅 Today: {{ $todayCounts }}
                </a>
                <a href="{{ route('matches.index', ['filter' => 'tomorrow']) }}" 
                   class="filter-btn {{ $filter == 'tomorrow' ? 'active' : '' }}">
                    ⏭️ Tomorrow: {{ $tomorrowCounts }}
                </a>
                <a href="{{ route('matches.index', ['filter' => 'all']) }}" 
                   class="filter-btn {{ $filter == 'all' ? 'active' : '' }}">
                    📋 All: {{ $matchTotal }}
                </a>
            </div>
        </div>

        <!-- Advanced Filters Form -->
        <div class="bg-white rounded-lg shadow-sm border p-4 mb-6">
            <form method="GET" action="{{ route('matches.index') }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
                <input type="hidden" name="filter" value="{{ $filter }}">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">League</label>
                    <select name="league_id" class="w-full border-gray-300 rounded-md text-sm">
                        <option value="">All Leagues</option>
                        @foreach($leagues as $league)
                            <option value="{{ $league->id }}" {{ request('league_id') == $league->id ? 'selected' : '' }}>
                                {{ $league->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Team</label>
                    <select name="team_id" class="w-full border-gray-300 rounded-md text-sm">
                        <option value="">All Teams</option>
                        @foreach($teams->take(50) as $team)
                            <option value="{{ $team->id }}" {{ request('team_id') == $team->id ? 'selected' : '' }}>
                                {{ $team->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                    <input type="date" name="date_from" value="{{ request('date_from', $filter == 'today' ? now()->format('Y-m-d') : '') }}" 
                           class="w-full border-gray-300 rounded-md text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                    <input type="date" name="date_to" value="{{ request('date_to', $filter == 'today' ? now()->format('Y-m-d') : '') }}" 
                           class="w-full border-gray-300 rounded-md text-sm">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full border-gray-300 rounded-md text-sm">
                        <option value="">All Status</option>
                        <option value="SCHEDULED" {{ request('status') == 'SCHEDULED' ? 'selected' : '' }}>Scheduled</option>
                        <option value="LIVE" {{ request('status') == 'LIVE' ? 'selected' : '' }}>Live</option>
                        <option value="FINISHED" {{ request('status') == 'FINISHED' ? 'selected' : '' }}>Finished</option>
                        <option value="POSTPONED" {{ request('status') == 'POSTPONED' ? 'selected' : '' }}>Postponed</option>
                        <option value="CANCELLED" {{ request('status') == 'CANCELLED' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 text-sm">
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Matches List -->
        <div class="space-y-4">
            <h3 class="text-lg font-semibold">
                ⚽ Matches 
                @if($filter != 'all')
                    ({{ ucfirst($filter) }} - {{ $matchTotal }} total)
                @else
                    ({{ $matchTotal }} total)
                @endif
            </h3>

            @if($matches->count() > 0)
                @foreach($matches as $match)
                    <a href="{{ route('matches.show', $match) }}" 
                       class="block bg-white rounded-lg shadow-sm border hover:shadow-md transition-shadow p-4">
                        <div class="flex items-center justify-between">
                            <!-- League -->
                            <div class="flex items-center space-x-2 text-sm text-gray-500 mb-2">
                                @if($match->league)
                                    @if($match->league->logo_url)
                                        <img src="{{ $match->league->logo_url }}" alt="{{ $match->league->name }}" class="w-4 h-4">
                                    @endif
                                    <span>{{ $match->league->name }}</span>
                                @else
                                    <span>League</span>
                                @endif
                            </div>

                            <!-- Status Badge -->
                            <div>
                                @if(in_array($match->status, ['LIVE', 'IN_PLAY', 'PAUSED', '1H', '2H', 'HT']))
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                        🔴 LIVE
                                    </span>
                                @elseif($match->status == 'FINISHED')
                                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                        ✅ FINISHED
                                    </span>
                                @else
                                    <span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                        {{ strtoupper($match->status ?? 'SCHEDULED') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Teams -->
                        <div class="flex items-center justify-between">
                            <!-- Home Team -->
                            <div class="flex items-center space-x-3 flex-1">
                                @if($match->homeTeam && $match->homeTeam->logo_url)
                                    <img src="{{ $match->homeTeam->logo_url }}" alt="{{ $match->homeTeam->name }}" class="w-8 h-8 object-contain">
                                @endif
                                <span class="font-medium">{{ $match->homeTeam ? $match->homeTeam->name : 'Home Team' }}</span>
                            </div>

                            <!-- Score/VS -->
                            <div class="mx-4 text-center">
                                @if($match->home_score !== null && $match->away_score !== null)
                                    <div class="text-xl font-bold">
                                        {{ $match->home_score }} - {{ $match->away_score }}
                                    </div>
                                @else
                                    <div class="text-gray-500 font-medium">vs</div>
                                @endif
                            </div>

                            <!-- Away Team -->
                            <div class="flex items-center space-x-3 flex-1 justify-end">
                                <span class="font-medium">{{ $match->awayTeam ? $match->awayTeam->name : 'Away Team' }}</span>
                                @if($match->awayTeam && $match->awayTeam->logo_url)
                                    <img src="{{ $match->awayTeam->logo_url }}" alt="{{ $match->awayTeam->name }}" class="w-8 h-8 object-contain">
                                @endif
                            </div>
                        </div>

                        <!-- Date and Time -->
                        <div class="mt-2 text-sm text-gray-500 text-center">
                            📅 {{ $match->match_date ? $match->match_date->format('M j, Y H:i') : 'TBD' }} | 
                            {{ strtoupper($match->status ?? 'SCHEDULED') }}
                        </div>

                        <!-- Match Information -->
                        <div class="mt-2 text-xs text-gray-600 text-center">
                            @if($match->venue_name)
                                📍 {{ $match->venue_name }}
                            @endif
                            @if($match->referee_name)
                                • 👨‍⚖️ {{ $match->referee_name }}
                            @endif
                        </div>

                        <!-- EPL Odds Link -->
                        @if($match->league && strtolower($match->league->name) === 'premier league')
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <div class="flex justify-center">
                                    <a href="{{ route('odds.index') }}#{{ $match->id }}" 
                                       class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                                        📊 View Odds
                                    </a>
                                </div>
                            </div>
                        @endif
                    </a>
                @endforeach

                <!-- Pagination -->
                @if($canPaginateMatches)
                    <div class="mt-6">
                        {{ $matches->links() }}
                    </div>
                @endif
            @else
                <div class="text-center py-8">
                    <div class="text-gray-500">
                        @if($filter == 'live')
                            No live matches at the moment
                        @elseif($filter == 'today')
                            No matches scheduled for today
                        @elseif($filter == 'tomorrow')
                            No matches scheduled for tomorrow
                        @else
                            No matches found
                        @endif
                    </div>
                    @if($filter != 'all')
                        <a href="{{ route('matches.index', ['filter' => 'all']) }}" 
                           class="text-blue-600 hover:text-blue-800 mt-2 inline-block">
                            View all matches
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

<style>
.filter-btn {
    @apply px-4 py-2 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 transition-colors text-sm font-medium;
}

.filter-btn.active {
    @apply bg-blue-600 text-white border-blue-600 hover:bg-blue-700;
}

@media (max-width: 640px) {
    .filter-btn {
        @apply text-xs px-3 py-1;
    }
}
</style>
@endsection
