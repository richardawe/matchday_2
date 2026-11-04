<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('griot_challenges', function (Blueprint $t) {
            $t->id();
            $t->string('status', 16)->default('open')->index(); // open, scored
            $t->dateTime('first_kickoff'); // UTC wall clock, like matches.match_date
            $t->dateTime('last_kickoff');
            $t->unsignedSmallInteger('griot_points')->nullable();
            $t->unsignedInteger('players')->default(0);
            $t->unsignedInteger('beaters')->default(0);
            $t->timestamp('scored_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('griot_challenge_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('griot_challenge_id')->constrained()->cascadeOnDelete();
            $t->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $t->unsignedTinyInteger('position');
            $t->unsignedTinyInteger('home_pick');
            $t->unsignedTinyInteger('away_pick');
            $t->json('facts')->nullable();
            $t->timestamps();
            $t->unique(['griot_challenge_id', 'match_id']);
        });
        Schema::create('griot_challenge_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('griot_challenge_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('points');
            $t->boolean('beat')->default(false);
            $t->json('picks')->nullable();
            $t->timestamps();
            $t->unique(['griot_challenge_id', 'user_id']);
        });
        Schema::create('griot_challenge_winners', function (Blueprint $t) {
            $t->id();
            $t->foreignId('griot_challenge_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Quoted by the winner in their message to Matchday's social account, so the admin knows who it is.
            $t->string('code', 16)->unique();
            $t->string('phone', 32)->nullable();
            $t->string('network', 32)->nullable();
            $t->text('note')->nullable();
            $t->timestamp('credited_at')->nullable();
            $t->foreignId('credited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['griot_challenge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('griot_challenge_winners');
        Schema::dropIfExists('griot_challenge_entries');
        Schema::dropIfExists('griot_challenge_matches');
        Schema::dropIfExists('griot_challenges');
    }
};
