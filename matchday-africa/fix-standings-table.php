<?php

chdir(__DIR__ . '/matchday-africa');
require_once 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable('.');
$dotenv->load();
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== FIXING STANDINGS TABLE ===\n";

try {
    // Check if standings table exists
    $tableExists = DB::select("SHOW TABLES LIKE 'standings'");
    
    if (empty($tableExists)) {
        echo "❌ Standings table doesn't exist. Creating it...\n";
        
        DB::statement("
            CREATE TABLE `standings` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `team_id` bigint(20) unsigned NOT NULL,
                `league_id` bigint(20) unsigned NOT NULL,
                `position` int(11) NOT NULL,
                `points` int(11) NOT NULL,
                `wins` int(11) NOT NULL,
                `draws` int(11) NOT NULL,
                `losses` int(11) NOT NULL,
                `goals_for` int(11) NOT NULL,
                `goals_against` int(11) NOT NULL,
                `goal_difference` int(11) NOT NULL,
                `played_games` int(11) NOT NULL,
                `form` varchar(255) DEFAULT NULL,
                `is_current` tinyint(1) NOT NULL DEFAULT 1,
                `season` varchar(255) DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT NULL,
                `updated_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `standings_league_id_position_index` (`league_id`,`position`),
                KEY `standings_team_id_league_id_index` (`team_id`,`league_id`),
                UNIQUE KEY `standings_team_id_league_id_season_unique` (`team_id`,`league_id`,`season`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        echo "✅ Created standings table\n";
    } else {
        echo "✅ Standings table exists. Checking columns...\n";
        
        // Get current columns
        $columns = DB::select("DESCRIBE standings");
        $columnNames = array_column($columns, 'Field');
        
        echo "Current columns: " . implode(', ', $columnNames) . "\n";
        
        // Check for missing columns and add them
        $requiredColumns = [
            'played_games' => 'int(11) NOT NULL DEFAULT 0',
            'form' => 'varchar(255) DEFAULT NULL',
            'is_current' => 'tinyint(1) NOT NULL DEFAULT 1',
            'season' => 'varchar(255) DEFAULT NULL'
        ];
        
        foreach ($requiredColumns as $column => $definition) {
            if (!in_array($column, $columnNames)) {
                echo "Adding missing column: $column\n";
                DB::statement("ALTER TABLE standings ADD COLUMN `$column` $definition");
                echo "✅ Added $column\n";
            }
        }
        
        // Add indexes if they don't exist
        try {
            DB::statement("ALTER TABLE standings ADD INDEX `standings_league_id_position_index` (`league_id`,`position`)");
            echo "✅ Added league_id_position index\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "Index creation error: " . $e->getMessage() . "\n";
            }
        }
        
        try {
            DB::statement("ALTER TABLE standings ADD INDEX `standings_team_id_league_id_index` (`team_id`,`league_id`)");
            echo "✅ Added team_id_league_id index\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "Index creation error: " . $e->getMessage() . "\n";
            }
        }
        
        try {
            DB::statement("ALTER TABLE standings ADD UNIQUE `standings_team_id_league_id_season_unique` (`team_id`,`league_id`,`season`)");
            echo "✅ Added unique constraint\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "Unique constraint error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Verify the table structure
    echo "\n=== FINAL TABLE STRUCTURE ===\n";
    $finalColumns = DB::select("DESCRIBE standings");
    foreach ($finalColumns as $col) {
        echo $col->Field . " (" . $col->Type . ") - " . ($col->Null == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n=== TABLE FIX COMPLETE ===\n";
