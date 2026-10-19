<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per story beat of a match (kick-off, goal, red card, half-time, full time),
// the source for the Griot's chapter cards and the full-time reel.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('match_moments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('key', 120)->unique();
            $table->string('type', 20);
            $table->string('side', 4)->nullable();
            $table->unsignedSmallInteger('minute')->nullable();
            $table->unsignedSmallInteger('extra_minute')->nullable();
            $table->string('player_name')->nullable();
            $table->string('assist_name')->nullable();
            $table->unsignedSmallInteger('home_score')->default(0);
            $table->unsignedSmallInteger('away_score')->default(0);
            $table->string('angle', 20)->nullable();
            $table->foreignId('match_event_id')->nullable()->constrained('match_events')->nullOnDelete();
            $table->timestamps();
            $table->index(['match_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_moments');
    }
};
