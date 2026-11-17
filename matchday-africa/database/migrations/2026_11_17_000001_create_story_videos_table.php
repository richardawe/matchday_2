<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match-story videos and radio videos (story:render): one row per subject and language.
 * hook/banter are editor overrides (null = generated from data); captions may be edited too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('story_videos')) {
            return;
        }
        Schema::create('story_videos', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 16); // match_story, radio_video
            $t->unsignedBigInteger('match_id')->nullable()->index();
            $t->unsignedBigInteger('radio_bulletin_id')->nullable()->index();
            $t->string('lang', 5)->default('en');
            $t->string('status', 16)->default('pending')->index(); // pending, rendering, ready, failed
            $t->string('source', 16)->default('auto'); // auto, admin, api
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedTinyInteger('refreshes')->default(0);
            $t->text('error')->nullable();
            $t->string('hook', 160)->nullable();
            $t->string('banter', 200)->nullable();
            $t->string('generated_hook', 160)->nullable();
            $t->string('generated_banter', 200)->nullable();
            $t->json('captions')->nullable();
            $t->boolean('captions_edited')->default(false);
            $t->unsignedBigInteger('player_id')->nullable();
            $t->json('photo')->nullable();
            $t->json('music')->nullable();
            $t->json('facts')->nullable();
            $t->json('beats')->nullable();
            $t->string('fingerprint', 64)->nullable();
            $t->decimal('duration_seconds', 8, 2)->nullable();
            $t->unsignedInteger('video_bytes')->nullable();
            $t->timestamp('rendered_at')->nullable();
            $t->timestamps();
            // One row per (kind, subject, lang), kept by StoryVideos::request() (MySQL unique keys ignore NULLs).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_videos');
    }
};
