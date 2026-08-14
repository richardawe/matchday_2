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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('provider'); // google, twitter, etc.
            $table->string('provider_id'); // The provider's user ID
            $table->text('provider_token')->nullable();
            $table->text('provider_refresh_token')->nullable();
            $table->timestamp('provider_token_expires_at')->nullable();
            $table->timestamps();

            // Ensure a user can only have one account per provider
            $table->unique(['user_id', 'provider']);
            $table->unique(['provider', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};