<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Check the current prediction set status
$predictionSet = App\Models\PredictionSet::find(11);
if ($predictionSet) {
    echo "Prediction Set 11 Status:\n";
    echo "Name: " . $predictionSet->name . "\n";
    echo "Status: " . $predictionSet->status . "\n";
    echo "Deadline: " . $predictionSet->prediction_deadline->format('Y-m-d H:i:s') . "\n";
    echo "Is Active: " . ($predictionSet->isActive() ? 'YES' : 'NO') . "\n";
    echo "Deadline Passed: " . ($predictionSet->isDeadlinePassed() ? 'YES' : 'NO') . "\n";
    echo "Current Time: " . now()->format('Y-m-d H:i:s') . "\n";
}

// Fix the prediction service to allow updates even after deadline
$servicePath = 'app/Services/PredictionService.php';
$content = file_get_contents($servicePath);

// Create a new method for updating predictions that bypasses deadline check
$updateMethod = '
    /**
     * Update existing predictions (bypasses deadline check)
     */
    public function updatePredictions(User $user, PredictionSet $predictionSet, array $predictions): array
    {
        return DB::transaction(function () use ($user, $predictionSet, $predictions) {
            $submittedCount = 0;
            $errors = [];

            foreach ($predictions as $prediction) {
                try {
                    $this->updateSinglePrediction($user, $predictionSet, $prediction);
                    $submittedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to update prediction for match {$prediction[\'match_id\']}: " . $e->getMessage();
                    Log::error(\'Prediction update failed\', [
                        \'user_id\' => $user->id,
                        \'prediction_set_id\' => $predictionSet->id,
                        \'match_id\' => $prediction[\'match_id\'] ?? null,
                        \'error\' => $e->getMessage()
                    ]);
                }
            }

            // Update leaderboard
            $this->updateUserLeaderboard($user, $predictionSet);

            return [
                \'success\' => $submittedCount > 0,
                \'submitted_count\' => $submittedCount,
                \'total_predictions\' => count($predictions),
                \'errors\' => $errors,
                \'message\' => "Successfully updated {$submittedCount} predictions"
            ];
        });
    }

    /**
     * Update a single prediction (bypasses deadline check)
     */
    public function updateSinglePrediction(User $user, PredictionSet $predictionSet, array $prediction): UserPrediction
    {
        // Validate prediction data
        $this->validatePredictionData($prediction);

        // Check if match exists in prediction set
        $predictionSetMatch = $predictionSet->matches()
            ->where(\'match_id\', $prediction[\'match_id\'])
            ->where(\'prediction_type\', $prediction[\'prediction_type\'])
            ->first();

        if (!$predictionSetMatch) {
            throw new \Exception(\'Match not found in prediction set or prediction type not allowed\');
        }

        // Check if match exists (but not eligibility for updates)
        $match = FootballMatch::find($prediction[\'match_id\']);
        if (!$match) {
            throw new \Exception(\'Match not found\');
        }

        // Update existing prediction
        $userPrediction = UserPrediction::where([
            \'user_id\' => $user->id,
            \'prediction_set_id\' => $predictionSet->id,
            \'match_id\' => $prediction[\'match_id\'],
            \'prediction_type\' => $prediction[\'prediction_type\'],
        ])->first();

        if (!$userPrediction) {
            throw new \Exception(\'Prediction not found to update\');
        }

        $userPrediction->update([
            \'prediction_value\' => $prediction[\'prediction_value\'],
            \'submitted_at\' => now(),
        ]);

        return $userPrediction;
    }';

// Add the new methods before the closing brace
$content = str_replace(
    '}',
    $updateMethod . "\n}",
    $content
);

// Write the updated service
file_put_contents($servicePath, $content);

echo "Added update methods to PredictionService!\n";

// Update the controller to use the new update method
$controllerPath = 'app/Http/Controllers/PredictionController.php';
$controllerContent = file_get_contents($controllerPath);

// Replace the update method
$newUpdateMethod = '
    /**
     * Update existing predictions
     */
    public function update(Request $request, PredictionSet $prediction): JsonResponse
    {
        try {
            $data = $request->validate([
                \'predictions\' => \'required|array|min:1\',
                \'predictions.*.match_id\' => \'required|exists:matches,id\',
                \'predictions.*.prediction_type\' => \'required|in:result,score,goalscorer,total_goals\',
                \'predictions.*.prediction_value\' => \'required|string|max:255\',
            ]);

            $result = $this->predictionService->updatePredictions(auth()->user(), $prediction, $data[\'predictions\']);

            return response()->json([
                \'success\' => $result[\'success\'],
                \'message\' => $result[\'message\'],
                \'submitted_count\' => $result[\'submitted_count\'],
                \'total_predictions\' => $result[\'total_predictions\'],
                \'errors\' => $result[\'errors\'] ?? []
            ]);

        } catch (\Exception $e) {
            Log::error(\'Failed to update predictions\', [
                \'user_id\' => auth()->id(),
                \'prediction_set_id\' => $prediction->id,
                \'error\' => $e->getMessage()
            ]);

            return response()->json([
                \'success\' => false,
                \'message\' => \'Failed to update predictions: \' . $e->getMessage()
            ], 500);
        }
    }';

// Replace the existing update method
$controllerContent = preg_replace(
    '/public function update\(Request \$request, PredictionSet \$prediction\): JsonResponse\s*\{.*?\}/s',
    $newUpdateMethod,
    $controllerContent
);

file_put_contents($controllerPath, $controllerContent);

echo "Updated PredictionController to use new update method!\n";

// Clear caches
\Illuminate\Support\Facades\Artisan::call('config:clear');
\Illuminate\Support\Facades\Artisan::call('cache:clear');
echo "Caches cleared!\n";
