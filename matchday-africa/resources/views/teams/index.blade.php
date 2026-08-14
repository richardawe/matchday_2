@extends('layouts.public')

@section('content')
<div class="py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">⚽ Teams</h1>
            <p class="mt-2 text-gray-600">All football teams</p>
        </div>

        <!-- Search and Filter -->
        <div class="bg-white rounded-lg shadow-sm border p-4 mb-6">
            <form method="GET" action="{{ route('teams.index') }}" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search Teams</label>
                    <input type="text" name="search" value="{{ request('search') }}" 
                           placeholder="Team name..." 
                           class="w-full border-gray-300 rounded-md text-sm">
                </div>

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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <select name="sort" class="w-full border-gray-300 rounded-md text-sm">
                        <option value="name" {{ request('sort') == 'name' ? 'selected' : '' }}>Name A-Z</option>
                        <option value="name_desc" {{ request('sort') == 'name_desc' ? 'selected' : '' }}>Name Z-A</option>
                        <option value="league" {{ request('sort') == 'league' ? 'selected' : '' }}>League</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 text-sm">
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Teams Grid -->
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-4">Teams ({{ $teams->total() }} total)</h3>
            
            @if($teams->count() > 0)
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    @foreach($teams as $team)
                        <a href="{{ route('teams.show', $team) }}" 
                           class="block bg-white rounded-lg shadow-sm border hover:shadow-md hover:border-blue-300 transition-all duration-200 p-4 group">
                            <div class="flex items-center space-x-3">
                                <!-- Team Logo -->
                                <div class="flex-shrink-0">
                                    @if($team->logo_url)
                                        <img src="{{ $team->logo_url }}" 
                                             alt="{{ $team->name }}" 
                                             class="w-12 h-12 object-contain">
                                    @else
                                        <div class="w-12 h-12 bg-gray-200 rounded-full flex items-center justify-center">
                                            <span class="text-gray-500 font-semibold text-sm">
                                                {{ substr($team->name, 0, 2) }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                                
                                <!-- Team Info -->
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-semibold text-gray-900 group-hover:text-blue-600 transition-colors truncate">
                                        {{ $team->name }}
                                    </h4>
                                    
                                    @if($team->league)
                                        <p class="text-sm text-gray-600 truncate">
                                            {{ $team->league->name }}
                                        </p>
                                    @else
                                        <p class="text-sm text-gray-400">No league assigned</p>
                                    @endif
                                    
                                    <!-- Team Stats -->
                                    <div class="flex items-center space-x-2 mt-1 text-xs text-gray-500">
                                        @if(method_exists($team, 'players') && $team->players_count > 0)
                                            <span>👥 {{ $team->players_count }} players</span>
                                        @endif
                                        
                                        @if($team->common_name && $team->common_name !== $team->name)
                                            <span>•</span>
                                            <span>{{ $team->common_name }}</span>
                                        @endif
                                    </div>
                                </div>
                                
                                <!-- Click Indicator -->
                                <div class="flex-shrink-0">
                                    <svg class="w-4 h-4 text-gray-400 group-hover:text-blue-600 transition-colors" 
                                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
                
                <!-- Pagination -->
                <div class="mt-6">
                    {{ $teams->links() }}
                </div>
            @else
                <div class="text-center py-8">
                    <div class="text-gray-500">
                        @if(request()->hasAny(['search', 'league_id']))
                            No teams found matching your criteria
                        @else
                            No teams available
                        @endif
                    </div>
                    @if(request()->hasAny(['search', 'league_id']))
                        <a href="{{ route('teams.index') }}" 
                           class="text-blue-600 hover:text-blue-800 mt-2 inline-block">
                            Clear filters
                        </a>
                    @endif
                </div>
            @endif
        </div>

        <!-- Quick Actions -->
        <div class="bg-blue-50 rounded-lg p-4">
            <h4 class="font-semibold text-blue-900 mb-2">Quick Actions</h4>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('leagues.index') }}" 
                   class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm hover:bg-blue-200">
                    🏆 Browse Leagues
                </a>
                <a href="{{ route('matches.index') }}" 
                   class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm hover:bg-blue-200">
                    ⚽ View Matches
                </a>
                <a href="{{ route('home') }}" 
                   class="px-3 py-1 bg-blue-100 text-blue-800 rounded text-sm hover:bg-blue-200">
                    🏠 Back to Home
                </a>
            </div>
        </div>
    </div>
</div>

<style>
/* Additional hover effects for teams grid */
.team-card:hover {
    transform: translateY(-2px);
}

@media (max-width: 640px) {
    .grid {
        grid-template-columns: 1fr;
    }
}
</style>
@endsection
