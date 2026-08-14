<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Check if User model exists and add the missing relationship
$userModelPath = 'app/Models/User.php';
$content = file_get_contents($userModelPath);

echo "Checking User model...\n";

// Check if userPredictions relationship exists
if (strpos($content, 'userPredictions()') === false) {
    echo "Adding userPredictions relationship to User model...\n";
    
    // Add the relationship method before the closing brace
    $relationshipMethod = '
    /**
     * Get user predictions
     */
    public function userPredictions()
    {
        return $this->hasMany(UserPrediction::class);
    }';
    
    $content = str_replace(
        '}',
        $relationshipMethod . "\n}",
        $content
    );
    
    file_put_contents($userModelPath, $content);
    echo "✅ Added userPredictions relationship to User model!\n";
} else {
    echo "✅ userPredictions relationship already exists!\n";
}

// Check if UserPrediction model exists
$userPredictionPath = 'app/Models/UserPrediction.php';
if (!file_exists($userPredictionPath)) {
    echo "Creating UserPrediction model...\n";
    
    $userPredictionModel = '<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPrediction extends Model
{
    protected $fillable = [
        \'user_id\',
        \'prediction_set_id\',
        \'match_id\',
        \'prediction_type\',
        \'prediction_value\',
        \'points_earned\',
        \'is_correct\',
        \'is_scored\',
        \'home_score_prediction\',
        \'away_score_prediction\',
        \'goalscorer_name\',
        \'total_goals_prediction\',
        \'submitted_at\'
    ];

    protected $casts = [
        \'points_earned\' => \'integer\',
        \'is_correct\' => \'boolean\',
        \'is_scored\' => \'boolean\',
        \'home_score_prediction\' => \'integer\',
        \'away_score_prediction\' => \'integer\',
        \'total_goals_prediction\' => \'integer\',
        \'submitted_at\' => \'datetime\',
    ];

    /**
     * Get the user who made this prediction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the prediction set
     */
    public function predictionSet(): BelongsTo
    {
        return $this->belongsTo(PredictionSet::class);
    }

    /**
     * Get the match
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, \'match_id\');
    }
}';
    
    file_put_contents($userPredictionPath, $userPredictionModel);
    echo "✅ Created UserPrediction model!\n";
} else {
    echo "✅ UserPrediction model already exists!\n";
}

// Clear caches
\Illuminate\Support\Facades\Artisan::call('config:clear');
\Illuminate\Support\Facades\Artisan::call('cache:clear');
echo "✅ Caches cleared!\n";
