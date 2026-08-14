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
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('football_data_id')->unique()->index();
            $table->foreignId('league_id')->constrained('leagues')->onDelete('cascade');
            $table->bigInteger('league_football_data_id')->index(); // For API reference
            $table->foreignId('home_team_id')->constrained('teams')->onDelete('cascade');
            $table->bigInteger('home_team_football_data_id')->index(); // For API reference
            $table->foreignId('away_team_id')->constrained('teams')->onDelete('cascade');
            $table->bigInteger('away_team_football_data_id')->index(); // For API reference
            
            // Match details
            $table->dateTime('match_date');
            $table->string('status', 20)->default('NS'); // NS, LIVE, FT, CANC, etc.
            $table->integer('minute')->nullable(); // Current minute if live
            $table->string('period', 20)->nullable(); // 1H, 2H, HT, FT, etc.
            
            // Scores
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->integer('home_score_ht')->nullable(); // Half-time score
            $table->integer('away_score_ht')->nullable(); // Half-time score
            
            // Match statistics (basic)
            $table->integer('home_possession')->nullable(); // Percentage
            $table->integer('away_possession')->nullable(); // Percentage
            $table->integer('home_shots')->nullable();
            $table->integer('away_shots')->nullable();
            $table->integer('home_shots_on_target')->nullable();
            $table->integer('away_shots_on_target')->nullable();
            $table->integer('home_corners')->nullable();
            $table->integer('away_corners')->nullable();
            $table->integer('home_fouls')->nullable();
            $table->integer('away_fouls')->nullable();
            $table->integer('home_yellow_cards')->nullable();
            $table->integer('away_yellow_cards')->nullable();
            $table->integer('home_red_cards')->nullable();
            $table->integer('away_red_cards')->nullable();
            
            // Additional info
            $table->string('venue_name')->nullable();
            $table->string('venue_city')->nullable();
            $table->string('referee_name')->nullable();
            $table->integer('attendance')->nullable();
            $table->string('weather')->nullable();
            $table->decimal('temperature', 3, 1)->nullable();
            
            // System fields
            $table->boolean('is_featured')->default(false);
            $table->boolean('has_live_coverage')->default(false);
            $table->timestamp('last_api_update')->nullable();
            $table->json('metadata')->nullable(); // For additional API data
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['match_date', 'status']);
            $table->index(['league_id', 'match_date']);
            $table->index(['status', 'is_featured']);
            $table->index(['home_team_id', 'away_team_id']);
            $table->index('last_api_update');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
