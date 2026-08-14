<?php
require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$userModelPath = 'app/Models/User.php';
$content = file_get_contents($userModelPath);

echo "Adding userPredictions relationship alias to User model...\n";

// Add the userPredictions method after the predictions method
$userPredictionsMethod = '
    /**
     * Get all predictions made by this user (alias for predictions)
     */
    public function userPredictions()
    {
        return $this->predictions();
    }';

// Insert after the predictions method
$content = str_replace(
    '    public function predictions()
    {
        return $this->hasMany(UserPrediction::class);
    }',
    '    public function predictions()
    {
        return $this->hasMany(UserPrediction::class);
    }

    /**
     * Get all predictions made by this user (alias for predictions)
     */
    public function userPredictions()
    {
        return $this->predictions();
    }',
    $content
);

file_put_contents($userModelPath, $content);

echo "✅ Added userPredictions relationship alias to User model!\n";

// Clear caches
\Illuminate\Support\Facades\Artisan::call('config:clear');
\Illuminate\Support\Facades\Artisan::call('cache:clear');
echo "✅ Caches cleared!\n";
