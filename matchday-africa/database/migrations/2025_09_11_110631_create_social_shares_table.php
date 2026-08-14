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
        Schema::create('social_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('shareable_type'); // App\Models\FootballMatch, App\Models\Blog, etc.
            $table->unsignedBigInteger('shareable_id');
            $table->string('platform'); // facebook, twitter, linkedin, whatsapp
            $table->text('share_url')->nullable();
            $table->timestamp('shared_at')->useCurrent();
            $table->timestamps();

            // Indexes for better performance
            $table->index(['shareable_type', 'shareable_id']);
            $table->index('platform');
            $table->index('shared_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_shares');
    }
};