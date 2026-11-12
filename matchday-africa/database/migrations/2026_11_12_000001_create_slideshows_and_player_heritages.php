<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colour slideshow videos (App\Models\Slideshow) and the heritage records that bring players of
 * African descent into scope (App\Models\PlayerHeritage). A slideshow keeps its own YouTube columns:
 * the match's youtube_* columns belong to the 20-second reel's Short.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('player_heritages')) {
            Schema::create('player_heritages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
                $table->string('country');                 // "Nigeria"
                $table->string('country_code', 3);         // FIFA-style, "NGA"
                $table->string('source_url', 2048)->nullable();
                $table->text('source_note')->nullable();
                $table->string('status', 16)->default('pending'); // pending, confirmed, rejected
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->unique(['player_id', 'country_code']);
                $table->index('status');
            });
        }

        if (!Schema::hasTable('slideshows')) {
            Schema::create('slideshows', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();            // "preview:123", "totw:2026-11-09"
                $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
                $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
                $table->string('type', 16);                 // preview, post_match, totw, milestone, national
                $table->string('status', 16)->default('pending'); // pending, rendering, ready, failed
                $table->integer('priority')->default(0);
                $table->json('facts')->nullable();          // every DB fact the deck uses, for the editor
                $table->json('slides')->nullable();         // the drawn slides' copy
                $table->text('caption')->nullable();
                $table->json('captions')->nullable();       // per platform: x, instagram, tiktok, youtube
                $table->string('landing_path')->nullable(); // the site page the post sends people to
                $table->json('photo')->nullable();          // Commons file, licence, artist, crop
                $table->json('music')->nullable();          // title, artist, licence, licence URL
                $table->string('video_path')->nullable();   // relative to storage/app
                $table->string('poster_path')->nullable();
                $table->decimal('duration_seconds', 5, 1)->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->text('error')->nullable();
                $table->timestamp('rendered_at')->nullable();
                // Review (the admin review queue builds on this): unapproved videos stay off the API and YouTube.
                $table->boolean('approved')->default(false);
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                // Its own YouTube Short, separate from the match's reel Short.
                $table->string('youtube_video_id')->nullable();
                $table->string('youtube_status', 16)->nullable();
                $table->unsignedTinyInteger('youtube_attempts')->default(0);
                $table->timestamp('youtube_next_at')->nullable();
                $table->text('youtube_error')->nullable();
                $table->timestamp('youtube_uploaded_at')->nullable();
                $table->json('youtube_upload_session')->nullable();
                $table->timestamps();
                $table->index(['status', 'priority']);
                $table->index(['approved', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('slideshows');
        Schema::dropIfExists('player_heritages');
    }
};
