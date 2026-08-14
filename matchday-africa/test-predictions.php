<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing Prediction System Setup...\n\n";

// Check if prediction tables exist
$tables = [
    'prediction_sets',
    'prediction_set_matches', 
    'user_predictions',
    'prediction_leaderboards'
];

foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        echo "✅ Table '$table' exists\n";
    } else {
        echo "❌ Table '$table' missing\n";
    }
}

// Check if prediction fields exist in matches table
$matchFields = ['is_prediction_eligible', 'prediction_deadline', 'prediction_types_enabled'];
foreach ($matchFields as $field) {
    if (Schema::hasColumn('matches', $field)) {
        echo "✅ Field '$field' exists in matches table\n";
    } else {
        echo "❌ Field '$field' missing in matches table\n";
    }
}

// Test creating a prediction set
try {
    $predictionSet = new \App\Models\PredictionSet();
    echo "✅ PredictionSet model can be instantiated\n";
} catch (Exception $e) {
    echo "❌ PredictionSet model error: " . $e->getMessage() . "\n";
}

// Test creating a user prediction
try {
    $userPrediction = new \App\Models\UserPrediction();
    echo "✅ UserPrediction model can be instantiated\n";
} catch (Exception $e) {
    echo "❌ UserPrediction model error: " . $e->getMessage() . "\n";
}

echo "\nPrediction system test completed!\n";
