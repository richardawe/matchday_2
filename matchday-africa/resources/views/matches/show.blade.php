@extends('layouts.public')

@section('content')
<section class="md-room-head"><div class="md-wrap"><div class="md-room-label"><span class="md-live-dot"></span>{{ in_array($match->status,['LIVE','1H','2H','HT'])?'LIVE MATCH ROOM':'MATCH ROOM' }} · {{ $match->league?->name }}</div>
<div class="md-scoreboard"><div><strong>{{ $match->homeTeam?->name }}</strong></div><b><span id="match-home-score">{{ $match->home_score ?? '–' }}</span> <i>:</i> <span id="match-away-score">{{ $match->away_score ?? '–' }}</span></b><div><strong>{{ $match->awayTeam?->name }}</strong></div></div>
<div class="md-momentum"><span style="width:{{ $momentum }}%"></span></div><div class="md-momentum-label"><span>{{ $match->homeTeam?->short_code ?: 'HOME' }} MOMENTUM</span><span>{{ $match->awayTeam?->short_code ?: 'AWAY' }}</span></div>
<div class="md-room-actions">@if($predictionSet)<a class="md-primary" href="{{ route('predictions.show',$predictionSet) }}">Make your call</a>@endif<a class="md-secondary" href="{{ route('war.match',$match) }}">Enter War mode</a><button class="md-secondary md-share" data-share-title="{{ $match->homeTeam?->name }} vs {{ $match->awayTeam?->name }}" data-share-text="Join the match room on Matchday Africa.">Share match</button></div></div></section>
@if($mythStory)<section id="match-chronicle" class="md-myth" data-url="{{ route('matches.chronicle',$match) }}" data-signature="{{ $mythStory['signature'] }}" data-active="{{ in_array($match->status,\App\Models\FootballMatch::LIVE_STATUSES,true) ? '1' : '0' }}">@include('partials.match-chronicle',['fresh'=>$match->last_api_update && $match->last_api_update->gte(now()->subMinutes(5))])</section>@endif
    <!-- Match Header -->
    <div class="bg-gradient-to-r from-blue-600 to-green-600 text-white py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <div class="text-sm text-blue-100 mb-4">
                    <a href="{{ route('leagues.show', $match->league) }}" class="hover:text-white underline">{{ $match->league->name }}</a>
                    • {{ $match->match_date->format('M j, Y H:i') }}
                </div>
                
                <div class="flex items-center justify-center space-x-8 mb-4">
                    <!-- Home Team -->
                    <div class="text-center">
                        <img src="{{ $match->homeTeam->logo }}" alt="{{ $match->homeTeam->name }}" class="w-20 h-20 mx-auto mb-2 object-contain">
                        <h2 class="text-xl font-bold">{{ $match->homeTeam->display_name }}</h2>
                    </div>
                    
                    <!-- Score -->
                    <div class="text-center">
                        <div class="text-4xl font-bold mb-2">{{ $match->score_display }}</div>
                        <div class="text-sm bg-white bg-opacity-20 px-3 py-1 rounded-full">
                            {{ $match->status_display }}
                        </div>
                    </div>
                    
                    <!-- Away Team -->
                    <div class="text-center">
                        <img src="{{ $match->awayTeam->logo }}" alt="{{ $match->awayTeam->name }}" class="w-20 h-20 mx-auto mb-2 object-contain">
                        <h2 class="text-xl font-bold">{{ $match->awayTeam->display_name }}</h2>
                    </div>
                </div>

                <!-- Match Info -->
                <div class="flex justify-center space-x-6 text-sm">
                    @if($match->venue_name)
                        <span>📍 {{ $match->venue_name }}</span>
                    @endif
                    @if($match->referee_name)
                        <span>👨‍⚖️ {{ $match->referee_name }}</span>
                    @endif
                    @if($match->attendance)
                        <span>👥 {{ number_format($match->attendance) }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Comprehensive Match Details Section -->
    <div class="bg-white border-b border-gray-200 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-gray-50 rounded-lg p-6">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 text-center">📋 Match Information</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    
                    <!-- Basic Match Info -->
                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="mr-2">📅</span> Basic Info
                        </h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Date:</span>
                                <span>{{ $match->match_date->format('M j, Y') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Time:</span>
                                <span>{{ $match->match_date->format('H:i') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Status:</span>
                                <span class="font-semibold">{{ $match->status_display }}</span>
                            </div>
                            @if($match->metadata && isset($match->metadata['matchday']))
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Matchday:</span>
                                    <span>{{ $match->metadata['matchday'] }}</span>
                                </div>
                            @endif
                            @if($match->metadata && isset($match->metadata['stage']))
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Stage:</span>
                                    <span class="capitalize">{{ str_replace('_', ' ', strtolower($match->metadata['stage'])) }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Venue & Location -->
                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="mr-2">🏟️</span> Venue & Location
                        </h3>
                        <div class="space-y-2 text-sm">
                            @if($match->venue_name)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Venue:</span>
                                    <span>{{ $match->venue_name }}</span>
                                </div>
                            @else
                                <div class="text-gray-500 italic">Venue not available</div>
                            @endif
                            @if($match->venue_city)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">City:</span>
                                    <span>{{ $match->venue_city }}</span>
                                </div>
                            @else
                                <div class="text-gray-500 italic">City not available</div>
                            @endif
                            @if($match->metadata && isset($match->metadata['area']))
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Country:</span>
                                    <div class="flex items-center space-x-1">
                                        @if(isset($match->metadata['area']['flag']))
                                            <img src="{{ $match->metadata['area']['flag'] }}" alt="{{ $match->metadata['area']['name'] }}" class="w-4 h-3">
                                        @endif
                                        <span>{{ $match->metadata['area']['name'] }}</span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Match Officials -->
                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="mr-2">👨‍⚖️</span> Officials
                        </h3>
                        <div class="space-y-2 text-sm">
                            @if($match->referee_name)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Referee:</span>
                                    <span>{{ $match->referee_name }}</span>
                                </div>
                            @else
                                <div class="text-gray-500 italic">Referee not available</div>
                            @endif
                            @if($match->metadata && isset($match->metadata['referees']) && count($match->metadata['referees']) > 0)
                                @foreach($match->metadata['referees'] as $referee)
                                    @if(isset($referee['nationality']))
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Nationality:</span>
                                            <span>{{ $referee['nationality'] }}</span>
                                        </div>
                                        @break
                                    @endif
                                @endforeach
                            @endif
                        </div>
                    </div>

                    <!-- Attendance & Environment -->
                    <div class="bg-white rounded-lg p-4 shadow-sm">
                        <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                            <span class="mr-2">👥</span> Attendance & Environment
                        </h3>
                        <div class="space-y-2 text-sm">
                            @if($match->attendance)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Attendance:</span>
                                    <span>{{ number_format($match->attendance) }}</span>
                                </div>
                            @else
                                <div class="text-gray-500 italic">Attendance not available</div>
                            @endif
                            @if($match->weather)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Weather:</span>
                                    <span>{{ $match->weather }}</span>
                                </div>
                            @else
                                <div class="text-gray-500 italic">Weather not available</div>
                            @endif
                            @if($match->temperature)
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Temperature:</span>
                                    <span>{{ $match->temperature }}°C</span>
                                </div>
                            @else
                                <div class="text-gray-500 italic">Temperature not available</div>
                            @endif
                        </div>
                    </div>

                    <!-- Score Details -->
                    @if($match->home_score !== null && $match->away_score !== null)
                        <div class="bg-white rounded-lg p-4 shadow-sm">
                            <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                                <span class="mr-2">⚽</span> Score Details
                            </h3>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Full Time:</span>
                                    <span class="font-bold text-lg">{{ $match->home_score }} - {{ $match->away_score }}</span>
                                </div>
                                @if($match->home_score_ht !== null && $match->away_score_ht !== null)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Half Time:</span>
                                        <span>{{ $match->home_score_ht }} - {{ $match->away_score_ht }}</span>
                                    </div>
                                @endif
                                @if($match->metadata && isset($match->metadata['score']['winner']))
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Winner:</span>
                                        <span class="font-semibold">
                                            @if($match->metadata['score']['winner'] === 'HOME_TEAM')
                                                {{ $match->homeTeam->name }}
                                            @elseif($match->metadata['score']['winner'] === 'AWAY_TEAM')
                                                {{ $match->awayTeam->name }}
                                            @else
                                                Draw
                                            @endif
                                        </span>
                                    </div>
                                @endif
                                @if($match->metadata && isset($match->metadata['score']['duration']))
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Duration:</span>
                                        <span class="capitalize">{{ str_replace('_', ' ', strtolower($match->metadata['score']['duration'])) }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- Live Match Info -->
                    @if(in_array($match->status, ['LIVE', 'IN_PLAY', 'PAUSED', '1H', '2H', 'HT']))
                        <div class="bg-white rounded-lg p-4 shadow-sm border-l-4 border-red-500">
                            <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                                <span class="mr-2">🔴</span> Live Match
                            </h3>
                            <div class="space-y-2 text-sm">
                                @if($match->minute)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Minute:</span>
                                        <span class="font-bold text-red-600">{{ $match->minute }}'</span>
                                    </div>
                                @endif
                                @if($match->period)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Period:</span>
                                        <span class="font-bold text-red-600">{{ $match->period }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- Competition Info -->
                    @if($match->metadata && isset($match->metadata['competition']))
                        <div class="bg-white rounded-lg p-4 shadow-sm">
                            <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                                <span class="mr-2">🏆</span> Competition
                            </h3>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Type:</span>
                                    <span class="capitalize">{{ str_replace('_', ' ', strtolower($match->metadata['competition']['type'])) }}</span>
                                </div>
                                @if(isset($match->metadata['competition']['emblem']))
                                    <div class="flex justify-between items-center">
                                        <span class="text-gray-600">Emblem:</span>
                                        <img src="{{ $match->metadata['competition']['emblem'] }}" alt="{{ $match->metadata['competition']['name'] }}" class="w-6 h-6">
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                </div>
            </div>
        </div>
    </div>

    <!-- Social Sharing Section -->
    <div class="bg-white border-b border-gray-200 py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-600">
                    Share this match with friends
                </div>
                <x-social-share-buttons :content="$match" :show-counts="true" />
            </div>
        </div>
    </div>

    <!-- Match Preview Section -->
    @if($matchPreview)
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border-b border-blue-200 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white rounded-lg shadow-sm border border-blue-100 overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center mb-4">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-indigo-500 rounded-full flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-3">
                                <h2 class="text-xl font-semibold text-blue-900">Match Preview</h2>
                            </div>
                            @if($matchPreview->is_featured)
                                <span class="ml-auto inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                    Featured
                                </span>
                            @endif
                        </div>
                        
                        <div class="prose prose-lg max-w-none text-gray-800">
                            {!! $matchPreview->preview_content !!}
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    @else
        <!-- No Preview Available -->
        <div class="bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-6 text-center">
                        <div class="w-16 h-16 bg-gray-200 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">No Match Preview Available</h3>
                        <p class="text-gray-600 mb-4">This match doesn't have an AI-generated preview yet.</p>
                        @auth
                            @if(auth()->user()->isAdmin())
                                <button onclick="generatePreview({{ $match->id }})" 
                                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                    </svg>
                                    Generate Match Preview
                                </button>
                            @endif
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- EPL Odds Section -->
    @if($match->league && strtolower($match->league->name) === 'premier league')
    <div class="bg-white border-b border-gray-200 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold mb-2 text-black">📊 Betting Odds</h2>
                <p class="text-gray-600">Compare odds from 27+ bookmakers for this EPL match</p>
            </div>
            
            <div class="flex justify-center">
                <a href="{{ route('odds.index') }}#{{ $match->id }}" 
                   class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-200 flex items-center space-x-2">
                    <span>📊</span>
                    <span>View Odds</span>
                </a>
            </div>
            
            <div class="mt-4 text-center text-sm text-gray-500">
                <span class="inline-flex items-center space-x-1">
                    <span>🔄</span>
                    <span>Updates every hour</span>
                </span>
            </div>
        </div>
    </div>
    @endif

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Main Match Details -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- Match Statistics -->
                    @if($match->home_shots || $match->away_shots || $match->home_possession || $match->away_possession)
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">
                                <h3 class="text-lg font-semibold mb-4">Match Statistics</h3>
                                <div class="space-y-4">
                                    
                                    @if($match->home_possession && $match->away_possession)
                                        <div>
                                            <div class="flex justify-between text-sm mb-1">
                                                <span>{{ $match->home_possession }}%</span>
                                                <span class="font-medium">Possession</span>
                                                <span>{{ $match->away_possession }}%</span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2">
                                                <div class="bg-blue-600 h-2 rounded-full" style="width: {{ $match->home_possession }}%"></div>
                                            </div>
                                        </div>
                                    @endif
                                    
                                    @if($match->home_shots && $match->away_shots)
                                        <div class="flex justify-between">
                                            <span class="font-semibold">{{ $match->home_shots }}</span>
                                            <span>Total Shots</span>
                                            <span class="font-semibold">{{ $match->away_shots }}</span>
                                        </div>
                                    @endif
                                    
                                    @if($match->home_shots_on_target && $match->away_shots_on_target)
                                        <div class="flex justify-between">
                                            <span class="font-semibold">{{ $match->home_shots_on_target }}</span>
                                            <span>Shots on Target</span>
                                            <span class="font-semibold">{{ $match->away_shots_on_target }}</span>
                                        </div>
                                    @endif
                                    
                                    @if($match->home_corners && $match->away_corners)
                                        <div class="flex justify-between">
                                            <span class="font-semibold">{{ $match->home_corners }}</span>
                                            <span>Corners</span>
                                            <span class="font-semibold">{{ $match->away_corners }}</span>
                                        </div>
                                    @endif
                                    
                                    @if($match->home_fouls && $match->away_fouls)
                                        <div class="flex justify-between">
                                            <span class="font-semibold">{{ $match->home_fouls }}</span>
                                            <span>Fouls</span>
                                            <span class="font-semibold">{{ $match->away_fouls }}</span>
                                        </div>
                                    @endif
                                    
                                    @if($match->home_yellow_cards && $match->away_yellow_cards)
                                        <div class="flex justify-between">
                                            <span class="font-semibold text-yellow-600">{{ $match->home_yellow_cards }}</span>
                                            <span>Yellow Cards</span>
                                            <span class="font-semibold text-yellow-600">{{ $match->away_yellow_cards }}</span>
                                        </div>
                                    @endif
                                    
                                    @if($match->home_red_cards && $match->away_red_cards)
                                        <div class="flex justify-between">
                                            <span class="font-semibold text-red-600">{{ $match->home_red_cards }}</span>
                                            <span>Red Cards</span>
                                            <span class="font-semibold text-red-600">{{ $match->away_red_cards }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Match Events -->
                    @if($events->count() > 0)
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">
                                <h3 class="text-lg font-semibold mb-4">Match Events</h3>
                                <div class="space-y-3">
                                    @foreach($events as $event)
                                        <div class="flex items-center space-x-4 py-2 border-l-4 
                                            @if($event->type === 'Goal') border-green-500 
                                            @elseif(in_array($event->type, ['Card', 'Yellow Card'])) border-yellow-500
                                            @elseif($event->type === 'Red Card') border-red-500
                                            @else border-blue-500 @endif pl-4">
                                            
                                            <div class="w-12 text-sm font-semibold">{{ $event->minute }}'</div>
                                            
                                            <div class="flex items-center space-x-2">
                                                @if($event->team && $event->team->logo)
                                                    <img src="{{ $event->team->logo }}" alt="{{ $event->team->name }}" class="w-5 h-5 object-contain">
                                                @endif
                                                
                                                @if($event->type === 'Goal')
                                                    <span class="text-green-600">⚽</span>
                                                @elseif(in_array($event->type, ['Card', 'Yellow Card']))
                                                    <span class="text-yellow-600">🟨</span>
                                                @elseif($event->type === 'Red Card')
                                                    <span class="text-red-600">🟥</span>
                                                @elseif($event->type === 'Substitution')
                                                    <span class="text-blue-600">🔄</span>
                                                @endif
                                            </div>
                                            
                                            <div class="flex-1">
                                                <div class="font-medium">{{ $event->player_name ?: $event->description }}</div>
                                                @if($event->assist_player_name)
                                                    <div class="text-sm text-gray-500">Assist: {{ $event->assist_player_name }}</div>
                                                @endif
                                                @if($event->reason)
                                                    <div class="text-sm text-gray-500">{{ $event->reason }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Head to Head -->
                    @if($headToHead->count() > 0)
                        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900">
                                <h3 class="text-lg font-semibold mb-4">Recent Head-to-Head</h3>
                                <div class="space-y-3">
                                    @foreach($headToHead as $h2h)
                                        <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-b-0">
                                            <div class="flex items-center space-x-3">
                                                <img src="{{ $h2h->homeTeam->logo }}" alt="{{ $h2h->homeTeam->name }}" class="w-6 h-6 object-contain">
                                                <span class="text-sm">{{ $h2h->homeTeam->display_name }}</span>
                                            </div>
                                            <div class="text-center">
                                                <div class="text-sm font-semibold">{{ $h2h->score_display }}</div>
                                                <div class="text-xs text-gray-500">{{ $h2h->match_date->format('M j, Y') }}</div>
                                            </div>
                                            <div class="flex items-center space-x-3">
                                                <span class="text-sm">{{ $h2h->awayTeam->display_name }}</span>
                                                <img src="{{ $h2h->awayTeam->logo }}" alt="{{ $h2h->awayTeam->name }}" class="w-6 h-6 object-contain">
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Sidebar -->
                <div class="lg:col-span-1 space-y-6">
                    

                    <!-- Upcoming Matches -->
                    @if($upcomingMatches->count() > 0)
                        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6 text-gray-900 dark:text-gray-100">
                                <h3 class="text-lg font-semibold mb-4">Upcoming Fixtures</h3>
                                <div class="space-y-3">
                                    @foreach($upcomingMatches as $upcoming)
                                        <a href="{{ route('matches.show', $upcoming) }}" class="block hover:bg-gray-50 dark:hover:bg-gray-700 p-2 rounded transition-colors">
                                            <div class="flex items-center space-x-2">
                                                <img src="{{ $upcoming->homeTeam->logo }}" alt="{{ $upcoming->homeTeam->name }}" class="w-5 h-5 object-contain">
                                                <span class="text-xs">{{ $upcoming->homeTeam->display_name }}</span>
                                                <span class="text-xs">vs</span>
                                                <span class="text-xs">{{ $upcoming->awayTeam->display_name }}</span>
                                                <img src="{{ $upcoming->awayTeam->logo }}" alt="{{ $upcoming->awayTeam->name }}" class="w-5 h-5 object-contain">
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">{{ $upcoming->match_date->format('M j, H:i') }}</div>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Chat & Banter Section -->
                    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="p-6 text-gray-900 dark:text-gray-100">
                            <h3 class="text-lg font-semibold mb-4 flex items-center">
                                💬 Chat & Banter
                                <span class="text-xs text-gray-500 dark:text-gray-400 ml-2 font-normal">
                                    Live discussion about this match
                                </span>
                            </h3>
                            
                            @auth
                                <!-- Chat Messages Container -->
                                <div id="chat-messages" class="mb-4 h-64 sm:h-80 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg p-2 sm:p-3 bg-gray-50 dark:bg-gray-900">
                                    <div class="space-y-2 sm:space-y-3">
                                        @foreach($recentChats as $chat)
                                            <div class="flex space-x-2 sm:space-x-3">
                                                <div class="flex-shrink-0">
                                                    <div class="w-6 h-6 sm:w-8 sm:h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs sm:text-sm font-semibold">
                                                        {{ substr($chat->user->name, 0, 1) }}
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center space-x-1 sm:space-x-2">
                                                        <span class="text-xs sm:text-sm font-medium truncate">{{ $chat->user->name }}</span>
                                                        <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0">{{ $chat->created_at->format('H:i') }}</span>
                                                    </div>
                                                    @if($chat->is_gif && $chat->gif_url)
                                                        <div class="mt-1">
                                                            <img src="{{ $chat->gif_url }}" alt="{{ $chat->gif_title ?? 'GIF' }}" class="max-w-full sm:max-w-xs rounded-lg">
                                                            @if($chat->gif_title)
                                                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 truncate">{{ $chat->gif_title }}</p>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <p class="text-xs sm:text-sm mt-1 break-words">{!! $chat->processed_message !!}</p>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <!-- Chat Input Form -->
                                <form id="chat-form" class="space-y-3" action="#" method="post">
                                    @csrf
                                    <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-2">
                                        <div class="flex space-x-2 flex-1">
                                            <input type="text" 
                                                   id="chat-input" 
                                                   placeholder="Type your message... (@username to mention)" 
                                                   class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm sm:text-base px-3 py-2">
                                            <button type="button" 
                                                    id="gif-button"
                                                    class="px-3 py-2 sm:px-4 bg-purple-500 hover:bg-purple-700 text-white rounded-lg text-xs sm:text-sm font-medium flex-shrink-0">
                                                📸
                                            </button>
                                        </div>
                                        <button type="submit" 
                                                class="px-4 py-2 bg-blue-500 hover:bg-blue-700 text-white rounded-lg text-sm font-medium w-full sm:w-auto">
                                            Send
                                        </button>
                                    </div>
                                    
                                    <!-- User mention suggestions -->
                                    <div id="mention-suggestions" class="hidden relative bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg max-h-40 overflow-y-auto z-10 w-full">
                                        <!-- Suggestions will be populated via JavaScript -->
                                    </div>
                                </form>

                                <!-- GIF Picker Modal -->
                                <div id="gif-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
                                    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 sm:p-6 w-full max-w-4xl max-h-[90vh] sm:max-h-[80vh] overflow-hidden">
                                        <div class="flex justify-between items-center mb-4">
                                            <h4 class="text-lg font-semibold">Choose a GIF</h4>
                                            <button id="close-gif-modal" class="text-gray-500 hover:text-gray-700">
                                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        
                                        <!-- GIF Search -->
                                        <div class="mb-4">
                                            <input type="text" 
                                                   id="gif-search-input" 
                                                   placeholder="Search for GIFs..." 
                                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                        </div>
                                        
                                        <!-- GIF Tabs -->
                                        <div class="flex space-x-4 mb-4">
                                            <button class="gif-tab active px-4 py-2 text-sm font-medium text-blue-600 border-b-2 border-blue-600" data-tab="trending">
                                                Trending
                                            </button>
                                            <button class="gif-tab px-4 py-2 text-sm font-medium text-gray-500 hover:text-blue-600" data-tab="football">
                                                Football
                                            </button>
                                        </div>
                                        
                                        <!-- GIF Grid -->
                                        <div id="gif-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 sm:gap-3 max-h-64 sm:max-h-96 overflow-y-auto">
                                            <!-- GIFs will be loaded here -->
                                        </div>
                                        
                                        <div id="gif-loading" class="text-center py-4 hidden">
                                            <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-blue-500"></div>
                                            <span class="ml-2">Loading GIFs...</span>
                                        </div>
                                    </div>
                                </div>
                                
                            @else
                                <div class="text-center py-8">
                                    <p class="text-gray-600 dark:text-gray-400 mb-4">Join the conversation! Sign in to chat with other fans.</p>
                                    <div class="space-x-4">
                                        <a href="{{ route('login') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                            Login
                                        </a>
                                        <a href="{{ route('register') }}" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                            Register
                                        </a>
                                    </div>
                                </div>
                            @endauth
                        </div>
                    </div>

                    <!-- Featured Match Previews -->
                    @if($featuredPreviews->count() > 0)
                        <div class="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 overflow-hidden shadow-sm sm:rounded-lg">
                            <div class="p-6">
                                <div class="flex items-center mb-4">
                                    <div class="flex-shrink-0">
                                        <div class="w-6 h-6 bg-gradient-to-r from-purple-500 to-indigo-500 rounded-full flex items-center justify-center">
                                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-2">
                                        <h3 class="text-sm font-semibold text-purple-900">Featured Previews</h3>
                                        <p class="text-xs text-purple-700">AI insights for similar matches</p>
                                    </div>
                                </div>
                                
                                <div class="space-y-4">
                                    @foreach($featuredPreviews as $preview)
                                        <div class="bg-white rounded-lg border border-purple-100 p-4 hover:shadow-md transition-shadow">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <img src="{{ $preview->match->homeTeam->logo }}" alt="{{ $preview->match->homeTeam->name }}" class="w-5 h-5 object-contain">
                                                <span class="text-xs font-medium text-gray-600">{{ $preview->match->homeTeam->name }}</span>
                                                <span class="text-gray-400 text-xs">vs</span>
                                                <span class="text-xs font-medium text-gray-600">{{ $preview->match->awayTeam->name }}</span>
                                                <img src="{{ $preview->match->awayTeam->logo }}" alt="{{ $preview->match->awayTeam->name }}" class="w-5 h-5 object-contain">
                                            </div>
                                            
                                            <div class="text-xs text-gray-500 mb-2">
                                                {{ $preview->match->league->name }} • {{ $preview->match->match_date->format('M j, H:i') }}
                                            </div>
                                            
                                            <p class="text-xs text-gray-700 line-clamp-2 mb-2">
                                                {{ Str::limit(strip_tags($preview->preview_content), 80) }}
                                            </p>
                                            
                                            <a href="{{ route('matches.show', $preview->match) }}" 
                                               class="text-xs text-purple-600 hover:text-purple-800 font-medium">
                                                View Preview →
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const chatMessages = document.getElementById('chat-messages');
    const gifButton = document.getElementById('gif-button');
    const gifModal = document.getElementById('gif-modal');
    const closeGifModal = document.getElementById('close-gif-modal');
    const gifSearchInput = document.getElementById('gif-search-input');
    const gifGrid = document.getElementById('gif-grid');
    const gifLoading = document.getElementById('gif-loading');
    const gifTabs = document.querySelectorAll('.gif-tab');
    const mentionSuggestions = document.getElementById('mention-suggestions');
    
    let currentGifTab = 'trending';
    let searchTimeout;
    let mentionTimeout;
    let selectedMentionIndex = -1;
    const matchId = {{ $match->id }};

    // Chat form submission
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const message = chatInput.value.trim();
            if (!message) return;
            
            sendMessage({ message: message });
            chatInput.value = '';
            hideMentionSuggestions();
        });
    }

    // Mention functionality
    if (chatInput) {
        chatInput.addEventListener('input', function(e) {
            const message = e.target.value;
            const cursorPosition = e.target.selectionStart;
            
            // Find the last @ symbol before cursor
            const beforeCursor = message.substring(0, cursorPosition);
            const atIndex = beforeCursor.lastIndexOf('@');
            
            if (atIndex !== -1) {
                const afterAt = beforeCursor.substring(atIndex + 1);
                // Only search if there's no space after @ and we're still typing the username
                if (!afterAt.includes(' ') && afterAt.length >= 0) {
                    clearTimeout(mentionTimeout);
                    mentionTimeout = setTimeout(() => {
                        searchUsers(afterAt);
                    }, 300);
                } else {
                    hideMentionSuggestions();
                }
            } else {
                hideMentionSuggestions();
            }
        });

        chatInput.addEventListener('keydown', function(e) {
            if (mentionSuggestions && !mentionSuggestions.classList.contains('hidden')) {
                const suggestions = mentionSuggestions.querySelectorAll('.mention-suggestion');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedMentionIndex = Math.min(selectedMentionIndex + 1, suggestions.length - 1);
                    updateMentionSelection(suggestions);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedMentionIndex = Math.max(selectedMentionIndex - 1, 0);
                    updateMentionSelection(suggestions);
                } else if (e.key === 'Enter' && selectedMentionIndex >= 0) {
                    e.preventDefault();
                    selectMention(suggestions[selectedMentionIndex]);
                } else if (e.key === 'Escape') {
                    hideMentionSuggestions();
                }
            }
        });
    }

    // GIF button click
    if (gifButton) {
        gifButton.addEventListener('click', function() {
            gifModal.classList.remove('hidden');
            loadGifs(currentGifTab);
        });
    }

    // Close GIF modal
    if (closeGifModal) {
        closeGifModal.addEventListener('click', function() {
            gifModal.classList.add('hidden');
        });
    }

    // GIF modal backdrop click
    if (gifModal) {
        gifModal.addEventListener('click', function(e) {
            if (e.target === gifModal) {
                gifModal.classList.add('hidden');
            }
        });
    }

    // GIF search
    if (gifSearchInput) {
        gifSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    searchGifs(query);
                }, 500);
            } else if (query.length === 0) {
                loadGifs(currentGifTab);
            }
        });
    }

    // GIF tabs
    gifTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const tabType = this.dataset.tab;
            
            // Update active tab
            gifTabs.forEach(t => {
                t.classList.remove('active', 'text-blue-600', 'border-b-2', 'border-blue-600');
                t.classList.add('text-gray-500');
            });
            
            this.classList.add('active', 'text-blue-600', 'border-b-2', 'border-blue-600');
            this.classList.remove('text-gray-500');
            
            currentGifTab = tabType;
            gifSearchInput.value = '';
            loadGifs(tabType);
        });
    });

    // Send message function
    function sendMessage(data) {
        fetch(`/matches/${matchId}/chats`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                addMessageToChat(data.chat);
                scrollToBottom();
            } else {
                console.error('Error sending message:', data.errors);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }

    // Add message to chat
    function addMessageToChat(chat) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'flex space-x-3';
        
        const avatarInitial = chat.user.name.charAt(0).toUpperCase();
        
        let messageContent;
        if (chat.is_gif && chat.gif_url) {
            messageContent = `
                <div class="mt-1">
                    <img src="${chat.gif_url}" alt="${chat.gif_title || 'GIF'}" class="max-w-xs rounded-lg">
                    ${chat.gif_title ? `<p class="text-xs text-gray-600 dark:text-gray-400 mt-1">${chat.gif_title}</p>` : ''}
                </div>
            `;
        } else {
            messageContent = `<p class="text-sm mt-1">${escapeHtml(chat.message)}</p>`;
        }
        
        messageDiv.innerHTML = `
            <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                    ${avatarInitial}
                </div>
            </div>
            <div class="flex-1">
                <div class="flex items-center space-x-2">
                    <span class="text-sm font-medium">${escapeHtml(chat.user.name)}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">${chat.created_at}</span>
                </div>
                ${messageContent}
            </div>
        `;
        
        chatMessages.querySelector('.space-y-3').appendChild(messageDiv);
    }

    // Load GIFs
    function loadGifs(type) {
        showGifLoading();
        
        let url;
        if (type === 'trending') {
            url = '/gifs/trending';
        } else if (type === 'football') {
            url = '/gifs/football';
        }
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                hideGifLoading();
                if (data.success) {
                    displayGifs(data.gifs);
                }
            })
            .catch(error => {
                console.error('Error loading GIFs:', error);
                hideGifLoading();
            });
    }

    // Search GIFs
    function searchGifs(query) {
        showGifLoading();
        
        fetch(`/gifs/search?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                hideGifLoading();
                if (data.success) {
                    displayGifs(data.gifs);
                }
            })
            .catch(error => {
                console.error('Error searching GIFs:', error);
                hideGifLoading();
            });
    }

    // Display GIFs
    function displayGifs(gifs) {
        gifGrid.innerHTML = '';
        
        gifs.forEach(gif => {
            const gifElement = document.createElement('div');
            gifElement.className = 'cursor-pointer hover:opacity-75 transition-opacity';
            gifElement.innerHTML = `
                <img src="${gif.preview_url || gif.url}" alt="${gif.title}" 
                     class="w-full h-24 object-cover rounded-lg"
                     data-gif-url="${gif.url}" 
                     data-gif-title="${gif.title}">
            `;
            
            gifElement.addEventListener('click', function() {
                const gifUrl = this.querySelector('img').dataset.gifUrl;
                const gifTitle = this.querySelector('img').dataset.gifTitle;
                
                sendMessage({
                    gif_url: gifUrl,
                    gif_title: gifTitle
                });
                
                gifModal.classList.add('hidden');
            });
            
            gifGrid.appendChild(gifElement);
        });
    }

    // Show/hide loading
    function showGifLoading() {
        gifLoading.classList.remove('hidden');
        gifGrid.classList.add('hidden');
    }

    function hideGifLoading() {
        gifLoading.classList.add('hidden');
        gifGrid.classList.remove('hidden');
    }

    // Scroll to bottom of chat
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Auto-refresh chat every 10 seconds
    setInterval(function() {
        refreshChat();
    }, 10000);

    // Refresh chat messages
    function refreshChat() {
        fetch(`/matches/${matchId}/chats`)
            .then(response => response.json())
            .then(data => {
                if (data.chats && data.chats.length > 0) {
                    // Simple implementation: replace all messages
                    // In production, you'd want to only add new messages
                    const chatContainer = chatMessages.querySelector('.space-y-3');
                    const currentCount = chatContainer.children.length;
                    
                    if (data.chats.length > currentCount) {
                        // Add only new messages
                        for (let i = currentCount; i < data.chats.length; i++) {
                            addMessageToChat(data.chats[i]);
                        }
                        scrollToBottom();
                    }
                }
            })
            .catch(error => {
                console.error('Error refreshing chat:', error);
            });
    }

    // Mention helper functions
    function searchUsers(query) {
        if (query.length < 1) {
            hideMentionSuggestions();
            return;
        }

        fetch(`/users/search?query=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.users.length > 0) {
                    showMentionSuggestions(data.users);
                } else {
                    hideMentionSuggestions();
                }
            })
            .catch(error => {
                console.error('Error searching users:', error);
                hideMentionSuggestions();
            });
    }

    function showMentionSuggestions(users) {
        if (!mentionSuggestions) return;

        mentionSuggestions.innerHTML = '';
        selectedMentionIndex = -1;

        users.forEach((user, index) => {
            const suggestion = document.createElement('div');
            suggestion.className = 'mention-suggestion px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer flex items-center space-x-2';
            suggestion.innerHTML = `
                <div class="w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs font-semibold">
                    ${user.avatar}
                </div>
                <span class="text-sm">${user.name}</span>
            `;

            suggestion.addEventListener('click', () => selectMention(suggestion));
            suggestion.setAttribute('data-username', user.name);
            mentionSuggestions.appendChild(suggestion);
        });

        mentionSuggestions.classList.remove('hidden');
    }

    function hideMentionSuggestions() {
        if (!mentionSuggestions) return;
        mentionSuggestions.classList.add('hidden');
        selectedMentionIndex = -1;
    }

    function updateMentionSelection(suggestions) {
        suggestions.forEach((suggestion, index) => {
            if (index === selectedMentionIndex) {
                suggestion.classList.add('bg-gray-100', 'dark:bg-gray-700');
            } else {
                suggestion.classList.remove('bg-gray-100', 'dark:bg-gray-700');
            }
        });
    }

    function selectMention(suggestionElement) {
        const username = suggestionElement.getAttribute('data-username');
        const message = chatInput.value;
        const cursorPosition = chatInput.selectionStart;
        
        // Find the @ symbol position
        const beforeCursor = message.substring(0, cursorPosition);
        const atIndex = beforeCursor.lastIndexOf('@');
        
        if (atIndex !== -1) {
            // Replace the partial mention with the complete username
            const beforeAt = message.substring(0, atIndex);
            const afterCursor = message.substring(cursorPosition);
            const newMessage = beforeAt + '@' + username + ' ' + afterCursor;
            
            chatInput.value = newMessage;
            
            // Position cursor after the mention
            const newCursorPosition = atIndex + username.length + 2;
            chatInput.setSelectionRange(newCursorPosition, newCursorPosition);
        }
        
        hideMentionSuggestions();
        chatInput.focus();
    }

    // Update addMessageToChat to handle mentions
    function addMessageToChat(chat) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'flex space-x-2 sm:space-x-3';
        
        const avatarInitial = chat.user.name.charAt(0).toUpperCase();
        
        let messageContent;
        if (chat.is_gif && chat.gif_url) {
            messageContent = `
                <div class="mt-1">
                    <img src="${chat.gif_url}" alt="${chat.gif_title || 'GIF'}" class="max-w-full sm:max-w-xs rounded-lg">
                    ${chat.gif_title ? `<p class="text-xs text-gray-600 dark:text-gray-400 mt-1 truncate">${chat.gif_title}</p>` : ''}
                </div>
            `;
        } else {
            // Use processed_message to show highlighted mentions
            messageContent = `<p class="text-xs sm:text-sm mt-1 break-words">${chat.processed_message || escapeHtml(chat.message)}</p>`;
        }
        
        messageDiv.innerHTML = `
            <div class="flex-shrink-0">
                <div class="w-6 h-6 sm:w-8 sm:h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs sm:text-sm font-semibold">
                    ${avatarInitial}
                </div>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-1 sm:space-x-2">
                    <span class="text-xs sm:text-sm font-medium truncate">${escapeHtml(chat.user.name)}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0">${chat.created_at}</span>
                </div>
                ${messageContent}
            </div>
        `;
        
        chatMessages.querySelector('.space-y-2').appendChild(messageDiv);
    }

    // Initial scroll to bottom
    scrollToBottom();
});

// Generate preview function
function generatePreview(matchId) {
        if (confirm('Are you sure you want to generate a match preview for this match?')) {
        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Generating...';
        button.disabled = true;
        
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
                alert('Match preview generated successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
                button.innerHTML = originalText;
                button.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while generating the preview');
            button.innerHTML = originalText;
            button.disabled = false;
        });
    }
}
</script>
@endpush
