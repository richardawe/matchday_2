<div id="realtime-updates" class="hidden">
    <!-- Real-time updates will appear here -->
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check if Pusher is available (you'll need to install pusher-js)
    if (typeof Pusher !== 'undefined') {
        initializeRealtimeUpdates();
    } else {
        console.log('Pusher not available. Install pusher-js for real-time updates.');
    }
});

function initializeRealtimeUpdates() {
    // Initialize Pusher (you'll need to configure this with your Pusher credentials)
    const pusher = new Pusher('{{ config('broadcasting.connections.pusher.key') }}', {
        cluster: '{{ config('broadcasting.connections.pusher.options.cluster') }}',
        encrypted: true
    });

    // Listen for match score updates
    const matchScoresChannel = pusher.subscribe('match-scores');
    matchScoresChannel.bind('score.updated', function(data) {
        showScoreUpdate(data);
    });

    // Listen for prediction scored events (private channel for authenticated users)
    @auth
    const predictionChannel = pusher.subscribe('private-user.{{ auth()->id() }}');
    predictionChannel.bind('prediction.scored', function(data) {
        showPredictionScored(data);
    });
    @endauth

    // Listen for general prediction updates
    const predictionsChannel = pusher.subscribe('predictions-scored');
    predictionsChannel.bind('prediction.scored', function(data) {
        // Update leaderboard if visible
        if (document.getElementById('leaderboard-container')) {
            updateLeaderboard();
        }
    });
}

function showScoreUpdate(data) {
    const container = document.getElementById('realtime-updates');
    if (!container) return;

    const notification = document.createElement('div');
    notification.className = 'fixed top-4 right-4 bg-blue-500 text-white p-4 rounded-lg shadow-lg z-50 max-w-sm';
    notification.innerHTML = `
        <div class="flex items-center justify-between">
            <div>
                <h4 class="font-bold">Score Update!</h4>
                <p class="text-sm">${data.home_team} ${data.home_score}-${data.away_score} ${data.away_team}</p>
                <p class="text-xs text-blue-200">${data.league} - ${data.minute || 'FT'}</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-blue-200 hover:text-white">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    `;

    container.appendChild(notification);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

function showPredictionScored(data) {
    const container = document.getElementById('realtime-updates');
    if (!container) return;

    const isCorrect = data.is_correct;
    const bgColor = isCorrect ? 'bg-green-500' : 'bg-red-500';
    const icon = isCorrect ? '✓' : '✗';

    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 ${bgColor} text-white p-4 rounded-lg shadow-lg z-50 max-w-sm`;
    notification.innerHTML = `
        <div class="flex items-center justify-between">
            <div>
                <h4 class="font-bold flex items-center">
                    <span class="mr-2">${icon}</span>
                    Prediction ${isCorrect ? 'Correct!' : 'Incorrect'}
                </h4>
                <p class="text-sm">${data.home_team} ${data.match_result} ${data.away_team}</p>
                <p class="text-sm">Your prediction: ${data.prediction_value}</p>
                <p class="text-xs">+${data.points_earned} points</p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-white hover:text-gray-200">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>
        </div>
    `;

    container.appendChild(notification);

    // Auto-remove after 7 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 7000);
}

function updateLeaderboard() {
    // Refresh leaderboard data via AJAX
    if (typeof fetch !== 'undefined') {
        fetch('/predictions/leaderboard', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.leaderboard) {
                // Update leaderboard display
                const leaderboardContainer = document.getElementById('leaderboard-container');
                if (leaderboardContainer) {
                    // This would need to be implemented based on your leaderboard structure
                    console.log('Leaderboard updated:', data);
                }
            }
        })
        .catch(error => console.error('Error updating leaderboard:', error));
    }
}
</script>
