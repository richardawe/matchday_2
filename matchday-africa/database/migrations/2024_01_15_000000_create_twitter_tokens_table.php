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
        Schema::create('twitter_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable(); // For user-specific tokens
            $table->string('access_token');
            $table->string('refresh_token')->nullable();
            $table->timestamp('expires_at');
            $table->string('scope')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('twitter_tokens');
    }
};
