@extends('layouts.public')

@section('content')
    <!-- Header -->
    <div class="bg-gradient-to-r from-blue-600 to-green-600 text-white py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <h1 class="text-4xl font-bold mb-4">⚽ All Leagues</h1>
                <p class="text-xl text-blue-100">Explore Football Competitions Worldwide</p>
            </div>
        </div>
    </div>

    <div class="py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Featured Leagues -->
            @if($featuredLeagues->count() > 0)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold mb-4">Featured Leagues</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach($featuredLeagues as $league)
                                <a href="{{ route('leagues.show', $league) }}" class="block border border-gray-200 rounded-lg p-6 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center space-x-4">
                                        @if($league->logo_url)
                                            <img src="{{ $league->logo_url }}" alt="{{ $league->name }}" class="w-12 h-12 object-contain">
                                        @else
                                            <div class="w-12 h-12 bg-gray-200 rounded-full flex items-center justify-center">
                                                <span class="text-sm font-bold">{{ substr($league->name, 0, 2) }}</span>
                                            </div>
                                        @endif
                                        <div class="flex-1">
                                            <h4 class="font-semibold">{{ $league->name }}</h4>
                                            <p class="text-sm text-gray-600">{{ $league->country_name }}</p>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm font-semibold text-blue-600">View Matches</div>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <!-- Other Leagues -->
            @if($otherLeagues->count() > 0)
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 text-gray-900">
                        <h3 class="text-lg font-semibold mb-4">Other Competitions</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach($otherLeagues as $league)
                                <a href="{{ route('leagues.show', $league) }}" class="block border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                                    <div class="flex items-center space-x-3">
                                        @if($league->logo_url)
                                            <img src="{{ $league->logo_url }}" alt="{{ $league->name }}" class="w-8 h-8 object-contain">
                                        @else
                                            <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                                                <span class="text-xs font-bold">{{ substr($league->name, 0, 2) }}</span>
                                            </div>
                                        @endif
                                        <div class="flex-1">
                                            <h5 class="font-medium">{{ $league->name }}</h5>
                                            <p class="text-xs text-gray-600">{{ $league->country_name }}</p>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection