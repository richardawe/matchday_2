@extends('layouts.app')

@section('title', 'EPL Betting Odds')

@section('header')
<div class="bg-gradient-to-r from-blue-600 to-purple-600 text-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="text-center">
            <h1 class="text-4xl font-bold mb-4">📊 EPL Betting Odds</h1>
            <p class="text-xl text-blue-100">Compare odds from 27+ bookmakers for Premier League matches</p>
            <div class="mt-4 flex justify-center space-x-4">
                <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full text-sm">
                    🔄 Hourly Updates
                </span>
                <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full text-sm">
                    🏆 Best Odds
                </span>
                <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full text-sm">
                    📱 Mobile Friendly
                </span>
            </div>
        </div>
    </div>
</div>
@endsection

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Loading State -->
    <div id="loadingState" class="text-center py-12">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
        <p class="mt-4 text-gray-600">Loading EPL odds...</p>
    </div>

    <!-- Error State -->
    <div id="errorState" class="hidden text-center py-12">
        <div class="bg-red-50 border border-red-200 rounded-lg p-6">
            <div class="text-red-600 text-6xl mb-4">⚠️</div>
            <h3 class="text-lg font-semibold text-red-800 mb-2">Unable to Load Odds</h3>
            <p class="text-red-600 mb-4">There was an error fetching the latest odds data.</p>
            <button onclick="loadOdds()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                Try Again
            </button>
        </div>
    </div>

    <!-- Odds Content -->
    <div id="oddsContent" class="hidden">
        
        <!-- Stats Header -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="text-3xl font-bold text-blue-600" id="totalMatches">-</div>
                    <div class="text-sm text-gray-600">Total Matches</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-green-600" id="totalBookmakers">-</div>
                    <div class="text-sm text-gray-600">Bookmakers</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-purple-600" id="lastUpdate">-</div>
                    <div class="text-sm text-gray-600">Last Updated</div>
                </div>
                <div class="text-center">
                    <div class="text-3xl font-bold text-orange-600" id="apiStatus">-</div>
                    <div class="text-sm text-gray-600">API Status</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">🔍 Filter Matches</h3>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                    <select id="dateFilter" class="w-full border border-gray-300 rounded-md px-3 py-2">
                        <option value="all">All Matches</option>
                        <option value="today">Today</option>
                        <option value="weekend">This Weekend</option>
                        <option value="week">This Week</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Bookmaker</label>
                    <select id="bookmakerFilter" class="w-full border border-gray-300 rounded-md px-3 py-2">
                        <option value="all">All Bookmakers</option>
                        <option value="fanduel">FanDuel</option>
                        <option value="draftkings">DraftKings</option>
                        <option value="paddypower">Paddy Power</option>
                        <option value="williamhill">William Hill</option>
                        <option value="betfair">Betfair</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sort By</label>
                    <select id="sortFilter" class="w-full border border-gray-300 rounded-md px-3 py-2">
                        <option value="time">Match Time</option>
                        <option value="home_team">Home Team</option>
                        <option value="away_team">Away Team</option>
                        <option value="best_odds">Best Odds</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button onclick="applyFilters()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                        Apply Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Matches List -->
        <div id="matchesList" class="space-y-6">
            <!-- Matches will be dynamically loaded here -->
        </div>

        <!-- No Matches State -->
        <div id="noMatchesState" class="hidden text-center py-12">
            <div class="text-gray-400 text-6xl mb-4">⚽</div>
            <h3 class="text-lg font-semibold text-gray-800 mb-2">No Matches Found</h3>
            <p class="text-gray-600">Try adjusting your filters to see more matches.</p>
        </div>
    </div>
</div>

<!-- Match Odds Modal -->
<div id="oddsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <!-- Modal Header -->
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900" id="modalTitle">Match Odds</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Modal Content -->
            <div id="modalContent">
                <!-- Odds comparison will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
let allMatches = [];
let filteredMatches = [];

// Load odds data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadOdds();
});

async function loadOdds() {
    try {
        showLoading();
        
        const response = await fetch('/api/odds/epl/weekend');
        const data = await response.json();
        
        if (data.success) {
            allMatches = data.data;
            filteredMatches = [...allMatches];
            displayMatches();
            updateStats();
            hideLoading();
        } else {
            showError();
        }
    } catch (error) {
        console.error('Error loading odds:', error);
        showError();
    }
}

function showLoading() {
    document.getElementById('loadingState').classList.remove('hidden');
    document.getElementById('errorState').classList.add('hidden');
    document.getElementById('oddsContent').classList.add('hidden');
}

function hideLoading() {
    document.getElementById('loadingState').classList.add('hidden');
    document.getElementById('oddsContent').classList.remove('hidden');
}

function showError() {
    document.getElementById('loadingState').classList.add('hidden');
    document.getElementById('errorState').classList.remove('hidden');
    document.getElementById('oddsContent').classList.add('hidden');
}

function displayMatches() {
    const matchesList = document.getElementById('matchesList');
    const noMatchesState = document.getElementById('noMatchesState');
    
    if (filteredMatches.length === 0) {
        matchesList.innerHTML = '';
        noMatchesState.classList.remove('hidden');
        return;
    }
    
    noMatchesState.classList.add('hidden');
    
    matchesList.innerHTML = filteredMatches.map(match => `
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                
                <!-- Match Info -->
                <div class="flex-1 mb-4 lg:mb-0">
                    <div class="flex items-center space-x-4">
                        <div class="text-center">
                            <div class="text-sm text-gray-600">${formatDate(match.commence_time)}</div>
                            <div class="text-xs text-gray-500">${formatTime(match.commence_time)}</div>
                        </div>
                        <div class="flex-1">
                            <div class="text-xl font-semibold text-gray-900">
                                ${match.home_team} vs ${match.away_team}
                            </div>
                            <div class="text-sm text-gray-600">Premier League</div>
                        </div>
                    </div>
                </div>
                
                <!-- Best Odds -->
                <div class="flex space-x-6 mb-4 lg:mb-0">
                    <div class="text-center">
                        <div class="text-sm text-gray-600">${match.home_team}</div>
                        <div class="text-2xl font-bold text-blue-600">${match.best_odds.home_win.price}</div>
                        <div class="text-xs text-gray-500">${match.best_odds.home_win.bookmaker}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-sm text-gray-600">Draw</div>
                        <div class="text-2xl font-bold text-gray-600">${match.best_odds.draw.price}</div>
                        <div class="text-xs text-gray-500">${match.best_odds.draw.bookmaker}</div>
                    </div>
                    <div class="text-center">
                        <div class="text-sm text-gray-600">${match.away_team}</div>
                        <div class="text-2xl font-bold text-red-600">${match.best_odds.away_win.price}</div>
                        <div class="text-xs text-gray-500">${match.best_odds.away_win.bookmaker}</div>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="flex space-x-2">
                    <button onclick="viewAllOdds('${match.match_id}', '${match.home_team}', '${match.away_team}')" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        View All Odds
                    </button>
                    <button onclick="compareOdds('${match.match_id}', '${match.home_team}', '${match.away_team}')" 
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        Compare
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

function updateStats() {
    document.getElementById('totalMatches').textContent = allMatches.length;
    document.getElementById('totalBookmakers').textContent = allMatches.length > 0 ? allMatches[0].bookmaker_count : 0;
    document.getElementById('lastUpdate').textContent = 'Live';
    document.getElementById('apiStatus').textContent = 'Active';
}

function applyFilters() {
    const dateFilter = document.getElementById('dateFilter').value;
    const bookmakerFilter = document.getElementById('bookmakerFilter').value;
    const sortFilter = document.getElementById('sortFilter').value;
    
    filteredMatches = [...allMatches];
    
    // Apply date filter
    if (dateFilter !== 'all') {
        const now = new Date();
        filteredMatches = filteredMatches.filter(match => {
            const matchDate = new Date(match.commence_time);
            switch (dateFilter) {
                case 'today':
                    return matchDate.toDateString() === now.toDateString();
                case 'weekend':
                    const day = matchDate.getDay();
                    return day === 0 || day === 6; // Saturday or Sunday
                case 'week':
                    const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
                    return matchDate >= weekAgo;
                default:
                    return true;
            }
        });
    }
    
    // Apply bookmaker filter
    if (bookmakerFilter !== 'all') {
        filteredMatches = filteredMatches.filter(match => {
            return match.all_bookmakers.some(bookmaker => bookmaker.key === bookmakerFilter);
        });
    }
    
    // Apply sort
    filteredMatches.sort((a, b) => {
        switch (sortFilter) {
            case 'time':
                return new Date(a.commence_time) - new Date(b.commence_time);
            case 'home_team':
                return a.home_team.localeCompare(b.home_team);
            case 'away_team':
                return a.away_team.localeCompare(b.away_team);
            case 'best_odds':
                const aBest = Math.max(a.best_odds.home_win.price, a.best_odds.away_win.price, a.best_odds.draw.price);
                const bBest = Math.max(b.best_odds.home_win.price, b.best_odds.away_win.price, b.best_odds.draw.price);
                return bBest - aBest;
            default:
                return 0;
        }
    });
    
    displayMatches();
}

function viewAllOdds(matchId, homeTeam, awayTeam) {
    const match = allMatches.find(m => m.match_id === matchId);
    if (!match) return;
    
    document.getElementById('modalTitle').textContent = `${homeTeam} vs ${awayTeam} - All Odds`;
    
    const modalContent = document.getElementById('modalContent');
    modalContent.innerHTML = `
        <div class="space-y-4">
            <div class="text-center mb-6">
                <h4 class="text-lg font-semibold text-gray-900">${homeTeam} vs ${awayTeam}</h4>
                <p class="text-sm text-gray-600">${formatDate(match.commence_time)} at ${formatTime(match.commence_time)}</p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bookmaker</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">${homeTeam}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Draw</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">${awayTeam}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        ${match.all_bookmakers.map(bookmaker => {
                            const h2hMarket = bookmaker.markets.find(m => m.key === 'h2h');
                            if (!h2hMarket) return '';
                            
                            const homeOdds = h2hMarket.outcomes.find(o => o.name === homeTeam);
                            const drawOdds = h2hMarket.outcomes.find(o => o.name === 'Draw');
                            const awayOdds = h2hMarket.outcomes.find(o => o.name === awayTeam);
                            
                            return `
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">${bookmaker.name}</td>
                                    <td class="px-4 py-3 text-center text-sm text-blue-600 font-semibold">${homeOdds ? homeOdds.price : '-'}</td>
                                    <td class="px-4 py-3 text-center text-sm text-gray-600 font-semibold">${drawOdds ? drawOdds.price : '-'}</td>
                                    <td class="px-4 py-3 text-center text-sm text-red-600 font-semibold">${awayOdds ? awayOdds.price : '-'}</td>
                                    <td class="px-4 py-3 text-center text-xs text-gray-500">${formatTime(bookmaker.last_update)}</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        </div>
    `;
    
    document.getElementById('oddsModal').classList.remove('hidden');
}

function compareOdds(matchId, homeTeam, awayTeam) {
    // For now, just show all odds - can be enhanced later
    viewAllOdds(matchId, homeTeam, awayTeam);
}

function closeModal() {
    document.getElementById('oddsModal').classList.add('hidden');
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        weekday: 'short', 
        month: 'short', 
        day: 'numeric' 
    });
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: false 
    });
}
</script>
@endsection
