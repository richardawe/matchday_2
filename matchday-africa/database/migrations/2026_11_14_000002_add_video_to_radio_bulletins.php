<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The vertical video of a published edition (radio:video): state, attempts, last error and the rendered file's length. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('radio_bulletins') || Schema::hasColumn('radio_bulletins', 'video_status')) {
            return;
        }
        Schema::table('radio_bulletins', function (Blueprint $t) {
            $t->string('video_status', 16)->nullable()->index(); // rendering, ready, failed, too_short
            $t->unsignedTinyInteger('video_attempts')->default(0);
            $t->text('video_error')->nullable();
            $t->decimal('video_seconds', 8, 2)->nullable();
            $t->unsignedInteger('video_bytes')->nullable();
            $t->timestamp('video_rendered_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('radio_bulletins') && Schema::hasColumn('radio_bulletins', 'video_status')) {
            Schema::table('radio_bulletins', function (Blueprint $t) {
                $t->dropIndex(['video_status']);
                $t->dropColumn(['video_status', 'video_attempts', 'video_error', 'video_seconds', 'video_bytes', 'video_rendered_at']);
            });
        }
    }
};
