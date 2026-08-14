@extends('layouts.public')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- League Header -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        @if($league->logo_url)
                            <img src="{{ $league->logo_url }}" alt="{{ $league->name }}" class="w-16 h-16 object-contain">
                        @endif
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">{{ $league->name }}</h1>
                            <p class="text-gray-600">League Table</p>
                        </div>
                    </div>
                    <div class="space-x-2">
                        <a href="{{ route('leagues.show', $league) }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                            ← Back to Matches
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Standings Table -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
                <h3 class="text-lg font-semibold mb-4">📊 Current Standings</h3>
                
                @if($standings->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pos</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">P</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">W</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">D</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">L</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">GF</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">GA</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">GD</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Pts</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Form</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($standings as $standing)
                                    <tr class="hover:bg-gray-50 {{ $standing->position <= 4 ? 'bg-green-50' : ($standing->position <= 6 ? 'bg-blue-50' : ($standing->position >= 18 ? 'bg-red-50' : '')) }}">
                                        <td class="px-3 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ $standing->position }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center space-x-3">
                                                @if($standing->team->logo_url)
                                                    <img src="{{ $standing->team->logo_url }}" alt="{{ $standing->team->name }}" class="w-8 h-8 object-contain">
                                                @endif
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ $standing->team->name }}
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-center">{{ $standing->played_games }}</td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-center">{{ $standing->wins }}</td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-center">{{ $standing->draws }}</td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-center">{{ $standing->losses }}</td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-center">{{ $standing->goals_for }}</td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-900 text-center">{{ $standing->goals_against }}</td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-center">
                                            <span class="{{ $standing->goal_difference >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                {{ $standing->goal_difference >= 0 ? '+' : '' }}{{ $standing->goal_difference }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-center">{{ $standing->points }}</td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-center">
                                            {!! $standing->form_display !!}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Legend -->
                    <div class="mt-4 flex flex-wrap gap-4 text-xs">
                        <div class="flex items-center space-x-1">
                            <div class="w-4 h-4 bg-green-50 border border-green-200"></div>
                            <span>Champions League</span>
                        </div>
                        <div class="flex items-center space-x-1">
                            <div class="w-4 h-4 bg-blue-50 border border-blue-200"></div>
                            <span>Europa League</span>
                        </div>
                        <div class="flex items-center space-x-1">
                            <div class="w-4 h-4 bg-red-50 border border-red-200"></div>
                            <span>Relegation Zone</span>
                        </div>
                    </div>
                @else
                    <div class="text-center py-8">
                        <p class="text-gray-500">No standings data available for this league.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
