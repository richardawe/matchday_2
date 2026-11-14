<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long videos for TikTok and the "As seen on TikTok" row on /reels: uploads that are not a reel,
 * slideshow or radio edition (social_videos), and featured slideshows and radio editions
 * (featured_items, keyed "slideshow:<id>" or "radio:<id>").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('social_videos')) {
            Schema::create('social_videos', function (Blueprint $table) {
                $table->id();
                $table->string('title', 150);
                $table->text('caption')->nullable();
                $table->json('captions')->nullable();
                $table->text('description')->nullable();
                $table->decimal('duration_seconds', 8, 1)->nullable();
                $table->string('type', 20)->default('documentary');
                $table->string('campaign', 60)->default('documentary');
                $table->boolean('featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->text('credits')->nullable();
                $table->string('landing_path', 255)->nullable();
                $table->string('video_path', 255)->nullable();
                $table->string('poster_path', 255)->nullable();
                $table->unsignedBigInteger('video_bytes')->nullable();
                $table->string('tiktok_url', 500)->nullable();
                $table->string('youtube_url', 500)->nullable();
                $table->string('status', 20)->default('approved');
                $table->string('source', 10)->default('admin');
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('queued_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'featured']);
            });
        }
        if (!Schema::hasTable('featured_items')) {
            Schema::create('featured_items', function (Blueprint $table) {
                $table->id();
                $table->string('subject', 40)->unique();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_items');
        Schema::dropIfExists('social_videos');
    }
};
