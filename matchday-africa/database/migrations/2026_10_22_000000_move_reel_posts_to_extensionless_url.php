<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Reels moved from /matches/{id}/reel.mp4 to /matches/{id}/reel: the host's static MP4 cache strips
// range requests, so Buffer could not read the video. Unsent reel posts take the new URL, and those
// that failed for that reason are queued again (they still expire when the next round starts).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('growth_posts', 'video_url')) {
            return;
        }
        DB::table('growth_posts')->where('type', 'reel')->where('video_url', 'like', '%/reel.mp4%')
            ->whereNotIn('status', ['sent', 'published'])->get()
            ->each(function ($post) {
                $retry = $post->status === 'failed' && str_contains((string) $post->last_error, 'could not be read from its URL');
                DB::table('growth_posts')->where('id', $post->id)->update(['video_url' => str_replace('/reel.mp4', '/reel', $post->video_url)]
                    + ($retry ? ['status' => 'queued', 'attempts' => 0, 'last_error' => null] : []));
            });
    }

    public function down(): void
    {
    }
};
