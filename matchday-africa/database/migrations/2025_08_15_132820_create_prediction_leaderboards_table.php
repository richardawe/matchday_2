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
        Schema::create('prediction_leaderboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_set_id')->nullable()->constrained('prediction_sets')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('total_points')->default(0);
            $table->integer('correct_predictions')->default(0);
            $table->integer('total_predictions')->default(0);
            $table->decimal('accuracy_percentage', 5, 2)->default(0.00);
            $table->integer('rank')->nullable();
            $table->enum('period', ['daily', 'weekly', 'monthly', 'all_time'])->default('all_time');
            $table->timestamps();
            
            $table->unique(['prediction_set_id', 'user_id', 'period']);
            $table->index(['prediction_set_id', 'period', 'rank']);
            $table->index(['user_id', 'period']);
            $table->index(['total_points', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prediction_leaderboards');
    }
};
