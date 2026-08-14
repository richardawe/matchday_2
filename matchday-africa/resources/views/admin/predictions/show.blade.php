@extends('layouts.admin')

@section('title', 'Prediction Set Details')

@section('header')
<div class="flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">{{ $prediction->name }}</h1>
        <p class="text-gray-600 mt-2">{{ $prediction->description }}</p>
    </div>
    <div class="flex space-x-3">
        <button onclick="rescorePredictions({{ $prediction->id }})" 
                class="bg-orange-500 hover:bg-orange-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
            🔄 Rescore Predictions
        </button>
        <a href="{{ route('admin.predictions.transparency') }}" 
           class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
            🔍 View Transparency
        </a>
        <a href="{{ route('admin.predictions.edit', $prediction) }}" 
           class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
            ✏️ Edit
        </a>
        <a href="{{ route('admin.predictions.index') }}" 
           class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg inline-flex items-center">
            ← Back to List
        </a>
    </div>
</div>
@endsection

@section('content')
<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6 text-gray-900">

        <!-- Status and Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-6 border border-blue-200">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <div class="text-sm font-medium text-blue-600">Status</div>
                        <div class="text-2xl font-bold text-blue-900">
                            <span class="px-3 py-1 rounded-full text-sm font-medium
                                @if($prediction->status === 'active') bg-green-100 text-green-800
                                @elseif($prediction->status === 'draft') bg-yellow-100 text-yellow-800
                                @elseif($prediction->status === 'closed') bg-red-100 text-red-800
                                @else bg-gray-100 text-gray-800
                                @endif">
                                {{ ucfirst($prediction->status) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <div class="text-sm font-medium text-green-600">Deadline</div>
                        <div class="text-lg font-bold text-green-900">{{ $prediction->prediction_deadline->format('M j, Y') }}</div>
                        <div class="text-sm text-green-700">{{ $prediction->prediction_deadline->format('H:i') }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-6 border border-purple-200">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-purple-500 rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <div class="text-sm font-medium text-purple-600">Matches</div>
                        <div class="text-2xl font-bold text-purple-900">{{ $prediction->matches->count() }}</div>
                        <div class="text-sm text-purple-700">{{ $prediction->matches->where('match.status', 'finished')->count() }} finished</div>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg p-6 border border-orange-200">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-orange-500 rounded-full flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <div class="text-sm font-medium text-orange-600">Participants</div>
                        <div class="text-2xl font-bold text-orange-900">{{ $analytics['basic_stats']['unique_users'] ?? 0 }}</div>
                        <div class="text-sm text-orange-700">{{ $analytics['basic_stats']['total_predictions'] ?? 0 }} predictions</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Tabs -->
        <div class="mb-8" x-data="{ activeTab: 'overview' }">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex space-x-8">
                    <button @click="activeTab = 'overview'" 
                            :class="activeTab === 'overview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="py-2 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                        📊 Overview
                    </button>
                    <button @click="activeTab = 'matches'" 
                            :class="activeTab === 'matches' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="py-2 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                        ⚽ Matches ({{ $prediction->matches->count() }})
                    </button>
                    <button @click="activeTab = 'participants'" 
                            :class="activeTab === 'participants' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="py-2 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                        👥 Participants ({{ $analytics['basic_stats']['unique_users'] ?? 0 }})
                    </button>
                    <button @click="activeTab = 'analytics'" 
                            :class="activeTab === 'analytics' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="py-2 px-1 border-b-2 font-medium text-sm transition-colors duration-200">
                        📈 Analytics
                    </button>
                </nav>
            </div>

            <!-- Tab Content -->
            <!-- Overview Tab -->
            <div x-show="activeTab === 'overview'" class="space-y-6">
                <!-- Key Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6 border border-blue-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-blue-600">Total Predictions</p>
                                <p class="text-3xl font-bold text-blue-900">{{ $analytics['basic_stats']['total_predictions'] ?? 0 }}</p>
                            </div>
                            <div class="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6 border border-green-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-green-600">Correct Predictions</p>
                                <p class="text-3xl font-bold text-green-900">{{ $analytics['basic_stats']['correct_predictions'] ?? 0 }}</p>
                            </div>
                            <div class="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-6 border border-purple-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-purple-600">Accuracy Rate</p>
                                <p class="text-3xl font-bold text-purple-900">{{ $analytics['basic_stats']['accuracy_percentage'] ?? 0 }}%</p>
                            </div>
                            <div class="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-xl p-6 border border-orange-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-orange-600">Avg per User</p>
                                <p class="text-3xl font-bold text-orange-900">{{ $analytics['basic_stats']['average_predictions_per_user'] ?? 0 }}</p>
                            </div>
                            <div class="w-12 h-12 bg-orange-500 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Information Cards -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Prediction Set Details -->
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">📋 Prediction Set Details</h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-sm font-medium text-gray-600">Created By</span>
                                <span class="text-sm text-gray-900">{{ $prediction->admin->name ?? 'Unknown' }}</span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-sm font-medium text-gray-600">Created At</span>
                                <span class="text-sm text-gray-900">{{ $prediction->created_at->format('M j, Y H:i') }}</span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-sm font-medium text-gray-600">Last Updated</span>
                                <span class="text-sm text-gray-900">{{ $prediction->updated_at->format('M j, Y H:i') }}</span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-sm font-medium text-gray-600">Time Remaining</span>
                                <span class="text-sm font-medium {{ $prediction->prediction_deadline->isFuture() ? 'text-green-600' : 'text-red-600' }}">
                                    @if($prediction->prediction_deadline->isFuture())
                                        {{ $prediction->prediction_deadline->diffForHumans() }}
                                    @else
                                        Expired {{ $prediction->prediction_deadline->diffForHumans() }}
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">🕒 Recent Activity</h3>
                        <div class="space-y-3">
                            @if(($analytics['basic_stats']['total_predictions'] ?? 0) > 0)
                                <div class="flex items-center space-x-3">
                                    <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                    <div>
                                        <p class="text-sm text-gray-900">Last prediction submitted</p>
                                        <p class="text-xs text-gray-500">{{ $analytics['basic_stats']['last_prediction'] ?? 'N/A' }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                    <div>
                                        <p class="text-sm text-gray-900">Prediction set created</p>
                                        <p class="text-xs text-gray-500">{{ $prediction->created_at->diffForHumans() }}</p>
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-4">
                                    <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-2">
                                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <p class="text-sm text-gray-500">No activity yet</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Matches Tab -->
            <div x-show="activeTab === 'matches'" class="space-y-6">
                <!-- Matches Summary -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-4 border border-blue-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-blue-600">Total Matches</p>
                                <p class="text-2xl font-bold text-blue-900">{{ $prediction->matches->count() }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-lg p-4 border border-green-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-green-600">Finished</p>
                                <p class="text-2xl font-bold text-green-900">{{ $prediction->matches->where('match.status', 'finished')->count() }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-orange-600">Upcoming</p>
                                <p class="text-2xl font-bold text-orange-900">{{ $prediction->matches->where('match.status', 'scheduled')->count() }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Matches List -->
                <div class="bg-white rounded-xl border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">⚽ Match Details</h3>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @forelse($prediction->matches as $predictionMatch)
                            <div class="p-6 hover:bg-gray-50 transition-colors duration-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-4">
                                            <!-- Match Teams -->
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-3">
                                                    <div class="text-center">
                                                        <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center">
                                                            <img src="{{ $predictionMatch->match->homeTeam->logo_url ?? asset('images/default-team.png') }}" 
                                                                 alt="{{ $predictionMatch->match->homeTeam->name }}" 
                                                                 class="w-8 h-8 rounded-full object-cover">
                                                        </div>
                                                        <p class="text-xs text-gray-600 mt-1">{{ $predictionMatch->match->homeTeam->name }}</p>
                                                    </div>
                                                    
                                                    <div class="text-center">
                                                        <div class="text-2xl font-bold text-gray-900">VS</div>
                                                        <div class="text-sm text-gray-500">{{ $predictionMatch->match->match_date->format('M j, H:i') }}</div>
                                                    </div>
                                                    
                                                    <div class="text-center">
                                                        <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center">
                                                            <img src="{{ $predictionMatch->match->awayTeam->logo_url ?? asset('images/default-team.png') }}" 
                                                                 alt="{{ $predictionMatch->match->awayTeam->name }}" 
                                                                 class="w-8 h-8 rounded-full object-cover">
                                                        </div>
                                                        <p class="text-xs text-gray-600 mt-1">{{ $predictionMatch->match->awayTeam->name }}</p>
                                                    </div>
                                                </div>
                                                
                                                <!-- Match Score (if finished) -->
                                                @if($predictionMatch->match->status === 'finished')
                                                    <div class="mt-2 text-center">
                                                        <span class="text-lg font-bold text-gray-900">
                                                            {{ $predictionMatch->match->home_score ?? '0' }} - {{ $predictionMatch->match->away_score ?? '0' }}
                                                        </span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        
                                        <!-- Match Info -->
                                        <div class="mt-3 flex items-center space-x-6 text-sm text-gray-500">
                                            <div class="flex items-center space-x-1">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                                                </svg>
                                                <span>{{ $predictionMatch->match->league->name }}</span>
                                            </div>
                                            <div class="flex items-center space-x-1">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                                                </svg>
                                                <span>{{ $predictionMatch->match->match_date->format('M j, Y H:i') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Prediction Details -->
                                    <div class="ml-6 text-right">
                                        <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                                            <div class="text-sm font-medium text-blue-600 mb-1">Prediction Type</div>
                                            <div class="text-lg font-bold text-blue-900">{{ ucfirst(str_replace('_', ' ', $predictionMatch->prediction_type)) }}</div>
                                            <div class="text-sm text-blue-700 mt-1">{{ $predictionMatch->points_value }} points</div>
                                        </div>
                                        
                                        <!-- Status Badge -->
                                        <div class="mt-2">
                                            <span class="px-2 py-1 rounded-full text-xs font-medium
                                                @if($predictionMatch->match->status === 'finished') bg-green-100 text-green-800
                                                @elseif($predictionMatch->match->status === 'scheduled') bg-yellow-100 text-yellow-800
                                                @elseif($predictionMatch->match->status === 'live') bg-red-100 text-red-800
                                                @else bg-gray-100 text-gray-800
                                                @endif">
                                                {{ ucfirst($predictionMatch->match->status) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="p-12 text-center">
                                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No matches found</h3>
                                <p class="text-gray-500">This prediction set doesn't have any matches yet.</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Participants Tab -->
            <div x-show="activeTab === 'participants'" class="space-y-6">
                <!-- Participants Summary -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-4 border border-blue-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-blue-600">Total Participants</p>
                                <p class="text-2xl font-bold text-blue-900">{{ $analytics['basic_stats']['unique_users'] ?? 0 }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-lg p-4 border border-green-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-green-600">Active Users</p>
                                <p class="text-2xl font-bold text-green-900">{{ $analytics['basic_stats']['active_users'] ?? 0 }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-4 border border-purple-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-purple-600">Avg Accuracy</p>
                                <p class="text-2xl font-bold text-purple-900">{{ $analytics['basic_stats']['accuracy_percentage'] ?? 0 }}%</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-orange-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-orange-600">Avg Predictions</p>
                                <p class="text-2xl font-bold text-orange-900">{{ $analytics['basic_stats']['average_predictions_per_user'] ?? 0 }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Leaderboard -->
                <div class="bg-white rounded-xl border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">🏆 Leaderboard</h3>
                        <p class="text-sm text-gray-500 mt-1">Click on a participant to view detailed point breakdown</p>
                    </div>
                    <div class="divide-y divide-gray-200">
                        @if(count($analytics['top_performers'] ?? []) > 0)
                            @foreach($analytics['top_performers'] as $performer)
                                <div class="p-6 hover:bg-gray-50 transition-colors duration-200" 
                                     x-data="{ showDetails: false }"
                                     @click="showDetails = !showDetails"
                                     style="cursor: pointer;">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4">
                                            <!-- Rank -->
                                            <div class="flex-shrink-0">
                                                @if($performer->rank <= 3)
                                                    <div class="w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-lg
                                                        @if($performer->rank == 1) bg-yellow-500
                                                        @elseif($performer->rank == 2) bg-gray-400
                                                        @else bg-orange-500
                                                        @endif">
                                                        {{ $performer->rank }}
                                                    </div>
                                                @else
                                                    <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center text-gray-600 font-bold">
                                                        {{ $performer->rank }}
                                                    </div>
                                                @endif
                                            </div>
                                            
                                            <!-- User Info -->
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-3">
                                                    <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                                        <span class="text-sm font-medium text-gray-600">
                                                            {{ substr($performer->user->name, 0, 2) }}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <h4 class="text-lg font-semibold text-gray-900">{{ $performer->user->name }}</h4>
                                                        <p class="text-sm text-gray-500">{{ $performer->user->email }}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Stats -->
                                        <div class="text-right">
                                            <div class="grid grid-cols-3 gap-6 text-center">
                                                <div>
                                                    <div class="text-2xl font-bold text-gray-900">{{ $performer->total_points }}</div>
                                                    <div class="text-sm text-gray-500">Points</div>
                                                </div>
                                                <div>
                                                    <div class="text-2xl font-bold text-green-600">{{ $performer->accuracy_percentage }}%</div>
                                                    <div class="text-sm text-gray-500">Accuracy</div>
                                                </div>
                                                <div>
                                                    <div class="text-2xl font-bold text-blue-600">{{ $performer->correct_predictions }}/{{ $performer->total_predictions }}</div>
                                                    <div class="text-sm text-gray-500">Correct</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Progress Bar -->
                                    <div class="mt-4">
                                        <div class="flex items-center justify-between text-sm text-gray-600 mb-1">
                                            <span>Prediction Accuracy</span>
                                            <span>{{ $performer->accuracy_percentage }}%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-gradient-to-r from-blue-500 to-green-500 h-2 rounded-full" 
                                                 style="width: {{ $performer->accuracy_percentage }}%"></div>
                                        </div>
                                    </div>

                                    <!-- Detailed Point Breakdown (Expandable) -->
                                    <div x-show="showDetails" 
                                         x-transition:enter="transition ease-out duration-200"
                                         x-transition:enter-start="opacity-0 transform scale-95"
                                         x-transition:enter-end="opacity-100 transform scale-100"
                                         x-transition:leave="transition ease-in duration-150"
                                         x-transition:leave-start="opacity-100 transform scale-100"
                                         x-transition:leave-end="opacity-0 transform scale-95"
                                         class="mt-6 pt-6 border-t border-gray-200">
                                        
                                        <h5 class="text-lg font-semibold text-gray-900 mb-4">📊 Point Breakdown</h5>
                                        
                                        <!-- Get user's detailed predictions for this prediction set -->
                                        @php
                                            $userPredictions = \App\Models\UserPrediction::where('user_id', $performer->user->id)
                                                ->where('prediction_set_id', $prediction->id)
                                                ->with(['match.homeTeam', 'match.awayTeam'])
                                                ->get();
                                            
                                            $pointBreakdown = [
                                                'exact_score' => 0,
                                                'partial_result' => 0,
                                                'result_correct' => 0,
                                                'total_goals_correct' => 0,
                                                'goalscorer_correct' => 0,
                                                'total_points' => 0
                                            ];
                                            
                                            foreach($userPredictions as $pred) {
                                                $pointBreakdown['total_points'] += $pred->points_earned ?? 0;
                                                
                                                if($pred->points_earned == 3) {
                                                    $pointBreakdown['exact_score']++;
                                                } elseif($pred->points_earned == 2) {
                                                    $pointBreakdown['goalscorer_correct']++;
                                                } elseif($pred->points_earned == 1) {
                                                    if($pred->prediction_type == 'result') {
                                                        $pointBreakdown['result_correct']++;
                                                    } elseif($pred->prediction_type == 'score') {
                                                        $pointBreakdown['partial_result']++;
                                                    } elseif($pred->prediction_type == 'total_goals') {
                                                        $pointBreakdown['total_goals_correct']++;
                                                    }
                                                }
                                            }
                                        @endphp
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                            <!-- Exact Score Predictions -->
                                            <div class="bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-lg p-4 border border-yellow-200">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="text-sm font-medium text-yellow-600">Exact Score</p>
                                                        <p class="text-2xl font-bold text-yellow-900">{{ $pointBreakdown['exact_score'] }}</p>
                                                        <p class="text-xs text-yellow-700">3 pts each</p>
                                                    </div>
                                                    <div class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center">
                                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Partial Result (Score predictions with correct result) -->
                                            <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-4 border border-blue-200">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="text-sm font-medium text-blue-600">Partial Result</p>
                                                        <p class="text-2xl font-bold text-blue-900">{{ $pointBreakdown['partial_result'] }}</p>
                                                        <p class="text-xs text-blue-700">1 pt each</p>
                                                    </div>
                                                    <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Result Predictions -->
                                            <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-lg p-4 border border-green-200">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="text-sm font-medium text-green-600">Result Correct</p>
                                                        <p class="text-2xl font-bold text-green-900">{{ $pointBreakdown['result_correct'] }}</p>
                                                        <p class="text-xs text-green-700">1 pt each</p>
                                                    </div>
                                                    <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Total Goals -->
                                            <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-4 border border-purple-200">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="text-sm font-medium text-purple-600">Total Goals</p>
                                                        <p class="text-2xl font-bold text-purple-900">{{ $pointBreakdown['total_goals_correct'] }}</p>
                                                        <p class="text-xs text-purple-700">1 pt each</p>
                                                    </div>
                                                    <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Goalscorer -->
                                            <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="text-sm font-medium text-orange-600">Goalscorer</p>
                                                        <p class="text-2xl font-bold text-orange-900">{{ $pointBreakdown['goalscorer_correct'] }}</p>
                                                        <p class="text-xs text-orange-700">2 pts each</p>
                                                    </div>
                                                    <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Total Points Summary -->
                                            <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-lg p-4 border border-gray-200">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="text-sm font-medium text-gray-600">Total Points</p>
                                                        <p class="text-2xl font-bold text-gray-900">{{ $pointBreakdown['total_points'] }}</p>
                                                        <p class="text-xs text-gray-700">Sum of all</p>
                                                    </div>
                                                    <div class="w-8 h-8 bg-gray-500 rounded-lg flex items-center justify-center">
                                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                        </svg>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Individual Predictions List -->
                                        <div class="mt-6">
                                            <h6 class="text-md font-semibold text-gray-900 mb-3">📝 Individual Predictions</h6>
                                            <div class="space-y-2 max-h-64 overflow-y-auto">
                                                @foreach($userPredictions as $pred)
                                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg text-sm">
                                                        <div class="flex-1">
                                                            <div class="font-medium text-gray-900">
                                                                {{ $pred->match->homeTeam->name ?? 'TBD' }} vs {{ $pred->match->awayTeam->name ?? 'TBD' }}
                                                            </div>
                                                            <div class="text-gray-500">
                                                                {{ ucfirst(str_replace('_', ' ', $pred->prediction_type)) }}: 
                                                                <span class="font-medium">{{ $pred->prediction_value }}</span>
                                                            </div>
                                                        </div>
                                                        <div class="text-right">
                                                            <div class="font-bold {{ $pred->points_earned > 0 ? 'text-green-600' : 'text-red-600' }}">
                                                                {{ $pred->points_earned ?? 0 }} pts
                                                            </div>
                                                            <div class="text-xs text-gray-500">
                                                                @if($pred->is_correct)
                                                                    ✅ Correct
                                                                @else
                                                                    ❌ Wrong
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <div class="p-12 text-center">
                                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No participants yet</h3>
                                <p class="text-gray-500">Users haven't started predicting on this set yet.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Analytics Tab -->
            <div x-show="activeTab === 'analytics'" class="space-y-6">
                <!-- Analytics Overview -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <div class="bg-gradient-to-r from-indigo-50 to-indigo-100 rounded-lg p-4 border border-indigo-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-indigo-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-indigo-600">Prediction Rate</p>
                                <p class="text-2xl font-bold text-indigo-900">{{ $analytics['basic_stats']['prediction_rate'] ?? 0 }}%</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-pink-50 to-pink-100 rounded-lg p-4 border border-pink-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-pink-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-pink-600">Engagement</p>
                                <p class="text-2xl font-bold text-pink-900">{{ $analytics['basic_stats']['engagement_score'] ?? 0 }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-teal-50 to-teal-100 rounded-lg p-4 border border-teal-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-teal-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.293l-3-3a1 1 0 00-1.414 0l-3 3a1 1 0 001.414 1.414L9 9.414V13a1 1 0 102 0V9.414l1.293 1.293a1 1 0 001.414-1.414z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-teal-600">Avg Points</p>
                                <p class="text-2xl font-bold text-teal-900">{{ $analytics['basic_stats']['average_points'] ?? 0 }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gradient-to-r from-cyan-50 to-cyan-100 rounded-lg p-4 border border-cyan-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 bg-cyan-500 rounded-lg flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-cyan-600">Completion</p>
                                <p class="text-2xl font-bold text-cyan-900">{{ $analytics['basic_stats']['completion_rate'] ?? 0 }}%</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Analytics -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Accuracy by Type -->
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">🎯 Accuracy by Prediction Type</h3>
                        @if(count($analytics['accuracy']['accuracy_by_type'] ?? []) > 0)
                            <div class="space-y-4">
                                @foreach($analytics['accuracy']['accuracy_by_type'] as $type)
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-medium text-gray-700">{{ ucfirst(str_replace('_', ' ', $type->prediction_type)) }}</span>
                                            <span class="text-sm font-semibold text-gray-900">{{ $type->accuracy }}%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-3">
                                            <div class="bg-gradient-to-r from-blue-500 to-green-500 h-3 rounded-full transition-all duration-500" 
                                                 style="width: {{ $type->accuracy }}%"></div>
                                        </div>
                                        <div class="flex justify-between text-xs text-gray-500">
                                            <span>{{ $type->correct_predictions ?? 0 }} correct</span>
                                            <span>{{ $type->total_predictions ?? 0 }} total</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-8">
                                <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                </div>
                                <p class="text-gray-500">No accuracy data available yet</p>
                            </div>
                        @endif
                    </div>

                    <!-- Participation by Match -->
                    <div class="bg-white rounded-xl border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">📊 Participation by Match</h3>
                        @if(count($analytics['participation']['participation_by_match'] ?? []) > 0)
                            <div class="space-y-4">
                                @foreach($analytics['participation']['participation_by_match'] as $match)
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div class="flex-1">
                                            <div class="text-sm font-medium text-gray-900">{{ $match['home_team'] }} vs {{ $match['away_team'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $match['match_date'] ?? 'N/A' }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-lg font-bold text-blue-600">{{ $match['predictions_count'] }}</div>
                                            <div class="text-xs text-gray-500">predictions</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-8">
                                <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                    </svg>
                                </div>
                                <p class="text-gray-500">No participation data available yet</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Performance Trends -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">📈 Performance Trends</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="text-center">
                            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                <svg class="w-8 h-8 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <h4 class="text-lg font-semibold text-gray-900">Best Performer</h4>
                            <p class="text-sm text-gray-600">{{ $analytics['top_performers'][0]->user->name ?? 'N/A' }}</p>
                            <p class="text-2xl font-bold text-green-600">{{ $analytics['top_performers'][0]->accuracy_percentage ?? 0 }}%</p>
                        </div>
                        <div class="text-center">
                            <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <h4 class="text-lg font-semibold text-gray-900">Most Active</h4>
                            <p class="text-sm text-gray-600">{{ $analytics['top_performers'][0]->user->name ?? 'N/A' }}</p>
                            <p class="text-2xl font-bold text-blue-600">{{ $analytics['top_performers'][0]->total_predictions ?? 0 }}</p>
                        </div>
                        <div class="text-center">
                            <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                <svg class="w-8 h-8 text-purple-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <h4 class="text-lg font-semibold text-gray-900">Highest Score</h4>
                            <p class="text-sm text-gray-600">{{ $analytics['top_performers'][0]->user->name ?? 'N/A' }}</p>
                            <p class="text-2xl font-bold text-purple-600">{{ $analytics['top_performers'][0]->total_points ?? 0 }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>        </div>
    </div>
</div>

<!-- Rescore Confirmation Modal -->
<div id="rescoreModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-orange-100">
                <svg class="h-6 w-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mt-2">Rescore Predictions</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">
                    This will rescore all predictions for this prediction set using the new scoring logic. 
                    This action cannot be undone.
                </p>
            </div>
            <div class="items-center px-4 py-3">
                <button id="confirmRescore" 
                        class="bg-orange-500 hover:bg-orange-700 text-white font-bold py-2 px-4 rounded mr-2">
                    Yes, Rescore
                </button>
                <button id="cancelRescore" 
                        class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentPredictionSetId = null;

function rescorePredictions(predictionSetId) {
    currentPredictionSetId = predictionSetId;
    document.getElementById('rescoreModal').classList.remove('hidden');
}

function hideRescoreModal() {
    document.getElementById('rescoreModal').classList.add('hidden');
    currentPredictionSetId = null;
}

// Event listeners
document.getElementById('confirmRescore').addEventListener('click', function() {
    if (currentPredictionSetId) {
        // Show loading state
        this.disabled = true;
        this.textContent = 'Rescoring...';
        
        // Make the request
        fetch(`/admin/predictions/${currentPredictionSetId}/rescore`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                alert(`Successfully rescored ${data.scored_count} predictions!`);
                // Reload the page to show updated data
                window.location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to rescore predictions'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error: Failed to rescore predictions');
        })
        .finally(() => {
            // Reset button state
            this.disabled = false;
            this.textContent = 'Yes, Rescore';
            hideRescoreModal();
        });
    }
});

document.getElementById('cancelRescore').addEventListener('click', hideRescoreModal);

// Close modal when clicking outside
document.getElementById('rescoreModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideRescoreModal();
    }
});
</script>
@endsection
