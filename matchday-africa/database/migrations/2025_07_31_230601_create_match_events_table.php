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
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('football_data_id')->unique()->index();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->bigInteger('match_football_data_id')->index(); // For API reference
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->bigInteger('team_football_data_id')->index(); // For API reference
            
            // Event details
            $table->string('type', 50); // goal, yellow_card, red_card, substitution, var, etc.
            $table->string('sub_type', 50)->nullable(); // penalty, own_goal, header, etc.
            $table->integer('minute'); // Match minute
            $table->integer('extra_minute')->nullable(); // Added time
            $table->string('period', 20)->nullable(); // 1H, 2H, ET1, ET2, PEN
            
            // Player information
            $table->string('player_name')->nullable();
            $table->bigInteger('player_sportmonks_id')->nullable()->index();
            $table->string('assist_player_name')->nullable();
            $table->bigInteger('assist_player_sportmonks_id')->nullable()->index();
            $table->string('related_player_name')->nullable(); // For substitutions (player coming off)
            $table->bigInteger('related_player_sportmonks_id')->nullable()->index();
            
            // Additional event data
            $table->string('reason')->nullable(); // For cards: unsporting behavior, etc.
            $table->text('description')->nullable(); // Event description
            $table->boolean('is_own_goal')->default(false);
            $table->boolean('is_penalty')->default(false);
            $table->decimal('x_coordinate', 5, 2)->nullable(); // Field position
            $table->decimal('y_coordinate', 5, 2)->nullable(); // Field position
            
            // System fields
            $table->integer('sort_order')->default(0); // For ordering events in same minute
            $table->json('metadata')->nullable(); // For additional API data
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['match_id', 'minute', 'sort_order']);
            $table->index(['match_id', 'type']);
            $table->index(['team_id', 'type']);
            $table->index(['player_sportmonks_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
