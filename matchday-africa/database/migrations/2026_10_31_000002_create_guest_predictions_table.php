<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Score predictions made without an account. They move onto the user's account when the
// visitor signs up or logs in (App\Services\GuestPredictions::claim).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guest_predictions')) {
            return;
        }
        Schema::create('guest_predictions', function (Blueprint $table) {
            $table->id();
            $table->string('guest_token', 64)->index();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedTinyInteger('home_score');
            $table->unsignedTinyInteger('away_score');
            $table->string('utm_source', 100)->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['guest_token', 'match_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_predictions');
    }
};
