<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$matchId = 2175;
$match = App\Models\FootballMatch::find($matchId);

if ($match) {
    echo "Match found: " . $match->homeTeam->name . " vs " . $match->awayTeam->name . "\n";
    echo "Current match date: " . $match->match_date->format('Y-m-d H:i:s') . "\n";
    echo "Current status: " . $match->status . "\n";
    echo "Current is_prediction_eligible: " . ($match->is_prediction_eligible ? 'true' : 'false') . "\n";
    
    // Make the match eligible for predictions
    $match->update([
        'is_prediction_eligible' => true,
        'prediction_deadline' => $match->match_date->subMinutes(30), // Set deadline 30 minutes before match
        'prediction_types_enabled' => ['result', 'score', 'goalscorer', 'total_goals']
    ]);
    
    echo "Match updated successfully!\n";
    echo "New is_prediction_eligible: " . ($match->is_prediction_eligible ? 'true' : 'false') . "\n";
    echo "Prediction deadline: " . $match->prediction_deadline->format('Y-m-d H:i:s') . "\n";
    echo "Enabled prediction types: " . implode(', ', $match->prediction_types_enabled) . "\n";
    
} else {
    echo "Match with ID $matchId not found\n";
}
