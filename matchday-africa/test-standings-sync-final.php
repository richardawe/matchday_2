<?php

chdir(__DIR__ . '/matchday-africa');
require_once 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable('.');
$dotenv->load();
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== TESTING STANDINGS SYNC FINAL ===\n";

try {
    $standingService = app(\App\Services\StandingService::class);
    
    // Test just Premier League first
    echo "Testing Premier League standings sync...\n";
    $result = $standingService->syncLeagueStandings(2021);
    
    echo "Result: " . $result['message'] . "\n";
    echo "Success: " . $result['success'] . "\n";
    echo "Errors: " . $result['errors'] . "\n";
    
    if ($result['success'] > 0) {
        echo "\n✅ SUCCESS! Checking database...\n";
        $standings = \App\Models\Standing::with('team')->where('league_id', 1)->orderBy('position')->limit(10)->get();
        
        echo "Top 10 Premier League standings:\n";
        foreach($standings as $standing) {
            echo "  " . $standing->position . ". " . $standing->team->name . " (" . $standing->points . " pts, " . $standing->played_games . " games)\n";
        }
        
        echo "\nTotal standings in database: " . \App\Models\Standing::count() . "\n";
    }
    
} catch(Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
