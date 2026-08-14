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
        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('favorable_type'); // App\Models\Team, App\Models\League, App\Models\Match
            $table->unsignedBigInteger('favorable_id'); // ID of the favorited entity
            $table->boolean('notifications_enabled')->default(true); // For match/team updates
            $table->string('notification_types')->default('all'); // goals, cards, all, etc.
            $table->timestamps();
            
            // Polymorphic index and unique constraint
            $table->index(['favorable_type', 'favorable_id']);
            $table->unique(['user_id', 'favorable_type', 'favorable_id']);
            $table->index(['user_id', 'notifications_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_favorites');
    }
};
