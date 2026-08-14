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
        Schema::create('user_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('prediction_set_id')->constrained('prediction_sets')->onDelete('cascade');
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->enum('prediction_type', ['result', 'score', 'goalscorer', 'total_goals']);
            $table->string('prediction_value', 255); // e.g., "2-1", "Home Win", "Over 2.5"
            $table->integer('points_earned')->default(0);
            $table->boolean('is_correct')->nullable();
            $table->datetime('submitted_at');
            $table->timestamps();
            
            $table->unique(['user_id', 'prediction_set_id', 'match_id', 'prediction_type'], 'user_pred_unique');
            $table->index(['user_id', 'prediction_set_id']);
            $table->index(['match_id', 'prediction_type']);
            $table->index(['is_correct', 'submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_predictions');
    }
};
