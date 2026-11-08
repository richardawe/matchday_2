<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where the posting bot (and admins) record that a social-queue item went out on a channel.
// subject is the queue item's id: "reel:<match_id>" or "growth:<growth_post_id>".
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('social_queue_posts')) {
            return;
        }
        Schema::create('social_queue_posts', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 100);
            $table->string('channel', 20);
            $table->string('external_post_id', 191)->nullable();
            $table->text('url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['subject', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_queue_posts');
    }
};
