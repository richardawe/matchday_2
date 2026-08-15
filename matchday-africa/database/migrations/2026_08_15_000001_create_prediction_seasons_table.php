<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prediction_seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('cleared_predictions')->default(0);
            $table->unsignedInteger('cleared_leaderboard_entries')->default(0);
            $table->unsignedInteger('archived_prediction_sets')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_seasons');
    }
};
