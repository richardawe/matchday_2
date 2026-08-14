<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$modelPath = 'app/Models/UserPrediction.php';
$content = file_get_contents($modelPath);

echo "Updating UserPrediction model with missing fields...\n";

// Update the fillable array to include all the fields we added to the database
$newFillable = [
    'user_id',
    'prediction_set_id',
    'match_id',
    'prediction_type',
    'prediction_value',
    'points_earned',
    'is_correct',
    'is_scored',
    'home_score_prediction',
    'away_score_prediction',
    'goalscorer_name',
    'total_goals_prediction',
    'submitted_at'
];

$content = str_replace(
    "protected \$fillable = [
        'user_id',
        'prediction_set_id',
        'match_id',
        'prediction_type',
        'prediction_value',
        'points_earned',
        'is_correct',
        'submitted_at'
    ];",
    "protected \$fillable = [
        'user_id',
        'prediction_set_id',
        'match_id',
        'prediction_type',
        'prediction_value',
        'points_earned',
        'is_correct',
        'is_scored',
        'home_score_prediction',
        'away_score_prediction',
        'goalscorer_name',
        'total_goals_prediction',
        'submitted_at'
    ];",
    $content
);

// Update the casts array
$content = str_replace(
    "protected \$casts = [
        'points_earned' => 'integer',
        'is_correct' => 'boolean',
        'submitted_at' => 'datetime',
    ];",
    "protected \$casts = [
        'points_earned' => 'integer',
        'is_correct' => 'boolean',
        'is_scored' => 'boolean',
        'home_score_prediction' => 'integer',
        'away_score_prediction' => 'integer',
        'total_goals_prediction' => 'integer',
        'submitted_at' => 'datetime',
    ];",
    $content
);

file_put_contents($modelPath, $content);

echo "✅ Updated UserPrediction model with missing fields!\n";

// Clear caches
\Illuminate\Support\Facades\Artisan::call('config:clear');
\Illuminate\Support\Facades\Artisan::call('cache:clear');
echo "✅ Caches cleared!\n";
