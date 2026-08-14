<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues')->onDelete('cascade');
            $table->bigInteger('league_football_data_id')->index(); // For API reference
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->bigInteger('team_football_data_id')->index(); // For API reference
            $table->string('season', 20); // e.g., "2024/25"
            $table->integer('season_year'); // e.g., 2024
            
            // Position and points
            $table->integer('position');
            $table->integer('points');
            $table->integer('recent_form_points')->nullable(); // Last 5 games points
            
            // Match statistics
            $table->integer('matches_played');
            $table->integer('wins');
            $table->integer('draws');
            $table->integer('losses');
            $table->integer('goals_for');
            $table->integer('goals_against');
            $table->integer('goal_difference'); // Calculated field for performance
            
            // Home/Away breakdown
            $table->integer('home_wins')->nullable();
            $table->integer('home_draws')->nullable();
            $table->integer('home_losses')->nullable();
            $table->integer('home_goals_for')->nullable();
            $table->integer('home_goals_against')->nullable();
            $table->integer('away_wins')->nullable();
            $table->integer('away_draws')->nullable();
            $table->integer('away_losses')->nullable();
            $table->integer('away_goals_for')->nullable();
            $table->integer('away_goals_against')->nullable();
            
            // Additional statistics
            $table->string('recent_form', 10)->nullable(); // e.g., "WWLDW" (last 5 games)
            $table->integer('clean_sheets')->nullable();
            $table->integer('failed_to_score')->nullable();
            $table->decimal('average_goals_for', 3, 2)->nullable();
            $table->decimal('average_goals_against', 3, 2)->nullable();
            
            // Qualification zones
            $table->string('qualification_zone')->nullable(); // Champions League, Europa, Relegation, etc.
            $table->string('zone_color', 7)->nullable(); // Hex color for display
            
            // System fields
            $table->date('calculation_date'); // When this standing was calculated
            $table->boolean('is_current')->default(true); // Is this the current standing
            $table->timestamp('last_api_update')->nullable();
            $table->json('metadata')->nullable(); // For additional API data
            $table->timestamps();
            
            // Unique constraint and indexes
            $table->unique(['league_id', 'team_id', 'season', 'calculation_date']);
            $table->index(['league_id', 'season', 'position']);
            $table->index(['team_id', 'season']);
            $table->index(['league_id', 'is_current']);
            $table->index(['position', 'points']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standings');
    }
};
