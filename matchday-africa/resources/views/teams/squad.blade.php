@extends('layouts.public')

@section('content')
<div class="py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Team Header -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    @if($team->logo_url)
                        <img src="{{ $team->logo_url }}" alt="{{ $team->name }}" class="w-16 h-16 object-contain">
                    @endif
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">{{ $team->name }}</h1>
                        <p class="text-gray-600">Team Squad</p>
                        @if($team->league)
                            <p class="text-sm text-gray-500">{{ $team->league->name }}</p>
                        @endif
                    </div>
                </div>
                <div class="text-right">
                    <a href="{{ route('teams.show', $team) }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        ← Back to Team
                    </a>
                </div>
            </div>
        </div>

        @if(isset($playersByPosition) && $playersByPosition->count() > 0)
            <!-- Squad Statistics -->
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ $squadStats['total_players'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">Total Players</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-green-600">{{ $squadStats['goalkeepers'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">🥅 GK</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-yellow-600">{{ $squadStats['defenders'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">🛡️ DEF</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-purple-600">{{ $squadStats['midfielders'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">⚽ MID</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-red-600">{{ $squadStats['forwards'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">🎯 FWD</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-indigo-600">{{ number_format($squadStats['average_age'] ?? 0, 1) }}</div>
                    <div class="text-sm text-gray-600">Avg Age</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-orange-600">{{ $squadStats['captains'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">👑 Captains</div>
                </div>
                <div class="bg-white rounded-lg shadow-sm border p-4 text-center">
                    <div class="text-2xl font-bold text-pink-600">{{ $squadStats['nationalities'] ?? 0 }}</div>
                    <div class="text-sm text-gray-600">🌍 Nations</div>
                </div>
            </div>

            <!-- Players by Position -->
            @foreach(['Goalkeeper', 'Defender', 'Midfielder', 'Attacker'] as $position)
                @if($playersByPosition->has($position))
                    <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
                        <h3 class="text-lg font-semibold mb-4">
                            @switch($position)
                                @case('Goalkeeper')
                                    🥅 Goalkeepers ({{ $playersByPosition[$position]->count() }})
                                    @break
                                @case('Defender')
                                    🛡️ Defenders ({{ $playersByPosition[$position]->count() }})
                                    @break
                                @case('Midfielder')
                                    ⚽ Midfielders ({{ $playersByPosition[$position]->count() }})
                                    @break
                                @case('Attacker')
                                    🎯 Forwards ({{ $playersByPosition[$position]->count() }})
                                    @break
                            @endswitch
                        </h3>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                            @foreach($playersByPosition[$position] as $player)
                                <div class="border rounded-lg p-4">
                                    <div class="flex items-center space-x-3 mb-3">
                                        <div class="w-8 h-8 bg-blue-600 text-white rounded flex items-center justify-center font-bold text-sm">
                                            {{ $player->shirt_number ?? '?' }}
                                        </div>
                                        @if($player->is_captain)
                                            <div class="text-yellow-500">👑</div>
                                        @endif
                                    </div>
                                    
                                    <h4 class="font-semibold text-gray-900 mb-1">{{ $player->name }}</h4>
                                    
                                    <div class="space-y-1 text-sm text-gray-600">
                                        @if($player->nationality)
                                            <div>🌍 {{ $player->nationality }}</div>
                                        @endif
                                        @if($player->age)
                                            <div>🎂 {{ $player->age }} years</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @else
            <!-- No Squad Data -->
            <div class="bg-white rounded-lg shadow-sm border p-8">
                <div class="text-center">
                    <div class="text-gray-500 text-lg mb-4">👥</div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No Squad Data Available</h3>
                    <p class="text-gray-600">
                        Squad information for {{ $team->name }} is not yet available.
                    </p>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
