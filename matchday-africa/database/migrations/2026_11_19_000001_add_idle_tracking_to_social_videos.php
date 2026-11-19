<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage cleanup for long videos (videos:prune-files): idle_since = when the video was taken down
 * (status off) or left neither featured nor queued; video_removed_at = when its mp4 was deleted.
 * Rows idle today start counting from their last update.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('social_videos')) {
            return;
        }
        Schema::table('social_videos', function (Blueprint $table) {
            if (!Schema::hasColumn('social_videos', 'idle_since')) {
                $table->timestamp('idle_since')->nullable()->after('queued_at');
            }
            if (!Schema::hasColumn('social_videos', 'video_removed_at')) {
                $table->timestamp('video_removed_at')->nullable()->after('idle_since');
            }
        });
        DB::table('social_videos')->whereNull('idle_since')
            ->where(fn ($q) => $q->where('status', 'off')->orWhere(fn ($q) => $q->where('featured', false)->where('status', '!=', 'queued')))
            ->update(['idle_since' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        if (Schema::hasTable('social_videos')) {
            Schema::table('social_videos', function (Blueprint $table) {
                $table->dropColumn(['idle_since', 'video_removed_at']);
            });
        }
    }
};
