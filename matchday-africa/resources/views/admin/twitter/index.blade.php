@extends('layouts.app')

@section('title', 'Twitter Management')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">🐦 Twitter Management</h1>
        <p class="mt-2 text-gray-600">Manage Twitter posts for @matchdayafrica</p>
    </div>

    <!-- Connection Status -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">📡 Connection Status</h2>
        
        <div id="connectionStatus" class="flex items-center space-x-3">
            @if($connectionTest['success'])
                <div class="flex items-center text-green-600">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-medium">Connected</span>
                </div>
                <span class="text-sm text-gray-500">@{{ $connectionTest['username'] ?? 'matchdayafrica' }}</span>
            @else
                <div class="flex items-center text-red-600">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-medium">Disconnected</span>
                </div>
                <span class="text-sm text-red-500">{{ $connectionTest['error'] ?? 'Unknown error' }}</span>
            @endif
        </div>

        <div class="mt-4 flex space-x-3">
            <button onclick="testConnection()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                🔄 Test Connection
            </button>
            <button onclick="authorizeTwitter()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                🔐 Authorize Twitter
            </button>
            <button onclick="showTestTweetModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                📝 Send Test Tweet
            </button>
            <button onclick="revokeTwitter()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                🚫 Revoke Access
            </button>
        </div>
    </div>

    <!-- Tweet Matches -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">📅 Tweet Match Links</h2>
        
        <form id="tweetForm" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="date" value="{{ today()->format('Y-m-d') }}" 
                           class="w-full border border-gray-300 rounded-md px-3 py-2">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tweet Type</label>
                    <select name="type" class="w-full border border-gray-300 rounded-md px-3 py-2">
                        <option value="both">Individual + Summary</option>
                        <option value="individual">Individual Only</option>
                        <option value="summary">Summary Only</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Delay (seconds)</label>
                    <input type="number" name="delay" value="30" min="10" max="300" 
                           class="w-full border border-gray-300 rounded-md px-3 py-2">
                </div>
            </div>
            
            <div class="flex space-x-3">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                    🐦 Tweet Matches
                </button>
                <button type="button" onclick="tweetToday()" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg font-medium">
                    📅 Tweet Today's Matches
                </button>
                <button type="button" onclick="tweetTomorrow()" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded-lg font-medium">
                    📅 Tweet Tomorrow's Matches
                </button>
            </div>
        </form>
    </div>

    <!-- Today's Matches -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">⚽ Today's Matches ({{ today()->format('M j, Y') }})</h2>
        
        @if($todayMatches->count() > 0)
            <div class="space-y-3">
                @foreach($todayMatches as $match)
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center space-x-4">
                            <div class="text-sm text-gray-500">
                                {{ \Carbon\Carbon::parse($match->match_date)->format('H:i') }}
                            </div>
                            <div class="font-medium">
                                {{ $match->homeTeam->name ?? 'TBD' }} vs {{ $match->awayTeam->name ?? 'TBD' }}
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ $match->competition }}
                            </div>
                        </div>
                        <a href="{{ route('matches.show', $match->id) }}" 
                           class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            View Match
                        </a>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500">No matches scheduled for today.</p>
        @endif
    </div>

    <!-- Tomorrow's Matches -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">⚽ Tomorrow's Matches ({{ today()->addDay()->format('M j, Y') }})</h2>
        
        @if($tomorrowMatches->count() > 0)
            <div class="space-y-3">
                @foreach($tomorrowMatches as $match)
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center space-x-4">
                            <div class="text-sm text-gray-500">
                                {{ \Carbon\Carbon::parse($match->match_date)->format('H:i') }}
                            </div>
                            <div class="font-medium">
                                {{ $match->homeTeam->name ?? 'TBD' }} vs {{ $match->awayTeam->name ?? 'TBD' }}
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ $match->competition }}
                            </div>
                        </div>
                        <a href="{{ route('matches.show', $match->id) }}" 
                           class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            View Match
                        </a>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500">No matches scheduled for tomorrow.</p>
        @endif
    </div>
</div>

<!-- Test Tweet Modal -->
<div id="testTweetModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Send Test Tweet</h3>
            <textarea id="testTweetText" rows="4" class="w-full border border-gray-300 rounded-md px-3 py-2" 
                      placeholder="Enter your test tweet text...">🧪 Test tweet from @matchdayafrica - {{ now()->format('Y-m-d H:i:s') }} #TestTweet #MatchdayAfrica</textarea>
            <div class="flex justify-end space-x-3 mt-4">
                <button onclick="hideTestTweetModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </button>
                <button onclick="sendTestTweet()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                    Send Tweet
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Test connection
function testConnection() {
    fetch('{{ route("admin.twitter.test-connection") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Connection test failed: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Connection test failed');
    });
}

// Authorize Twitter OAuth 2.0
function authorizeTwitter() {
    fetch('{{ route("admin.twitter.authorize") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.auth_url;
        } else {
            alert('Authorization failed: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Authorization failed');
    });
}

// Revoke Twitter access
function revokeTwitter() {
    if (confirm('Are you sure you want to revoke Twitter access? This will prevent posting tweets.')) {
        fetch('{{ route("twitter.oauth.revoke") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Revoke failed: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Revoke failed');
        });
    }
}

// Tweet matches
document.getElementById('tweetForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    fetch('{{ route("admin.twitter.tweet-matches") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Tweets posted successfully!');
        } else {
            alert('Failed to post tweets: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to post tweets');
    });
});

// Quick tweet functions
function tweetToday() {
    document.querySelector('input[name="date"]').value = '{{ today()->format("Y-m-d") }}';
    document.getElementById('tweetForm').dispatchEvent(new Event('submit'));
}

function tweetTomorrow() {
    document.querySelector('input[name="date"]').value = '{{ today()->addDay()->format("Y-m-d") }}';
    document.getElementById('tweetForm').dispatchEvent(new Event('submit'));
}

// Test tweet modal
function showTestTweetModal() {
    document.getElementById('testTweetModal').classList.remove('hidden');
}

function hideTestTweetModal() {
    document.getElementById('testTweetModal').classList.add('hidden');
}

function sendTestTweet() {
    const text = document.getElementById('testTweetText').value;
    
    if (!text.trim()) {
        alert('Please enter tweet text');
        return;
    }
    
    fetch('{{ route("admin.twitter.send-test-tweet") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ text: text })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Test tweet sent successfully! Tweet ID: ' + data.tweet_id);
            hideTestTweetModal();
        } else {
            alert('Failed to send test tweet: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to send test tweet');
    });
}
</script>
@endsection
