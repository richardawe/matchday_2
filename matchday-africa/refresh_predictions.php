<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$matchId = 2175;
$match = App\Models\FootballMatch::find($matchId);

if ($match) {
    echo "Match found: " . $match->homeTeam->name . " vs " . $match->awayTeam->name . "\n";
    
    // Get all user predictions for this match
    $userPredictions = App\Models\UserPrediction::where('match_id', $matchId)->get();
    echo "Found " . $userPredictions->count() . " user predictions for this match\n";
    
    // Refresh each prediction to ensure it's properly recorded
    foreach ($userPredictions as $prediction) {
        echo "Processing prediction ID: " . $prediction->id . " for user: " . $prediction->user_id . "\n";
        
        // Touch the prediction to update timestamps
        $prediction->touch();
        
        // Ensure the prediction has all required fields
        if (empty($prediction->submitted_at)) {
            $prediction->update(['submitted_at' => $prediction->created_at]);
        }
    }
    
    // Clear relevant caches
    \Illuminate\Support\Facades\Cache::forget("match_{$matchId}_predictions");
    \Illuminate\Support\Facades\Cache::forget("user_predictions_match_{$matchId}");
    
    echo "Predictions refreshed successfully!\n";
    
} else {
    echo "Match with ID $matchId not found\n";
}
