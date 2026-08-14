<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$predictionSetId = 11;
$predictionSet = App\Models\PredictionSet::find($predictionSetId);

if ($predictionSet) {
    echo "Prediction Set: " . $predictionSet->name . "\n";
    echo "Description: " . $predictionSet->description . "\n";
    echo "Status: " . $predictionSet->status . "\n";
    echo "Deadline: " . $predictionSet->prediction_deadline->format('Y-m-d H:i:s') . "\n\n";
    
    // Get all matches in this prediction set
    $matches = $predictionSet->matches()->with(['match.homeTeam', 'match.awayTeam', 'match.league'])->get();
    
    echo "Matches in this prediction set (" . $matches->count() . " total):\n";
    echo str_repeat("-", 80) . "\n";
    
    foreach ($matches as $predictionMatch) {
        $match = $predictionMatch->match;
        echo "Match ID: " . $match->id . "\n";
        echo "Teams: " . $match->homeTeam->name . " vs " . $match->awayTeam->name . "\n";
        echo "League: " . $match->league->name . "\n";
        echo "Date: " . $match->match_date->format('Y-m-d H:i:s') . "\n";
        echo "Status: " . $match->status . "\n";
        echo "Score: " . ($match->home_score !== null ? $match->home_score . "-" . $match->away_score : "Not scored") . "\n";
        echo "Prediction Type: " . $predictionMatch->prediction_type . "\n";
        echo "Points Value: " . $predictionMatch->points_value . "\n";
        echo str_repeat("-", 80) . "\n";
    }
    
} else {
    echo "Prediction set with ID $predictionSetId not found\n";
}
