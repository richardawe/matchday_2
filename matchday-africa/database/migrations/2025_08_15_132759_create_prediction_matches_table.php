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
        Schema::create('prediction_set_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_set_id')->constrained('prediction_sets')->onDelete('cascade');
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->enum('prediction_type', ['result', 'score', 'goalscorer', 'total_goals'])->default('result');
            $table->integer('points_value')->default(1);
            $table->timestamps();
            
            $table->unique(['prediction_set_id', 'match_id']);
            $table->index(['prediction_set_id', 'prediction_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prediction_set_matches');
    }
};
