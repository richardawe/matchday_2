<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reels uploaded to YouTube by hand before automatic uploads existed (match id => video id),
     * recorded so they are never uploaded again.
     */
    private const ALREADY_UPLOADED = [
        5085 => 'se0Iqt-b7wE', 5042 => 'tEtSIGWFNaI', 4916 => 'vOK0rbHwS00', 4950 => 'm_YsOGuf3ns',
        4938 => 'J9rR4A9cNK8', 4906 => '7etlm90mqtg', 4891 => 'fJ68XBjWjzU', 4889 => '5jFp9m9_1ig',
        4896 => 'PRuXHgW3O2E', 4895 => 'HufmxKk5B-Y', 4898 => 'D10Z2en3Gvk', 4877 => '2EzqhyawdWY',
    ];

    public function up(): void
    {
        // The reel (generated/reels/<id>/<id>.mp4) belongs to the match, so its YouTube Short does too.
        if (!Schema::hasColumn('matches', 'youtube_video_id')) Schema::table('matches', function (Blueprint $t) {
            $t->string('youtube_video_id', 32)->nullable();
            $t->dateTime('youtube_uploaded_at')->nullable();
            // pending, uploading, uploaded, failed (null: never queued).
            $t->string('youtube_status', 12)->nullable()->index();
            $t->unsignedTinyInteger('youtube_attempts')->default(0);
            $t->dateTime('youtube_next_at')->nullable();
            $t->text('youtube_error')->nullable();
            // The resumable upload session in progress ({"size":…, "url":…}), so a retry resumes it.
            $t->text('youtube_upload_session')->nullable();
        });
        // The scopes Google granted when the channel was connected (null: connected before this was recorded).
        if (Schema::hasTable('youtube_accounts') && !Schema::hasColumn('youtube_accounts', 'scopes')) {
            Schema::table('youtube_accounts', fn (Blueprint $t) => $t->text('scopes')->nullable());
        }

        foreach (self::ALREADY_UPLOADED as $match => $video) {
            DB::table('matches')->where('id', $match)->whereNull('youtube_video_id')->update([
                'youtube_video_id' => $video, 'youtube_status' => 'uploaded', 'youtube_uploaded_at' => now(),
                'youtube_error' => null, 'youtube_next_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('matches', 'youtube_video_id')) Schema::table('matches', function (Blueprint $t) {
            $t->dropIndex(['youtube_status']);
            $t->dropColumn(['youtube_video_id', 'youtube_uploaded_at', 'youtube_status', 'youtube_attempts', 'youtube_next_at', 'youtube_error', 'youtube_upload_session']);
        });
        if (Schema::hasColumn('youtube_accounts', 'scopes')) {
            Schema::table('youtube_accounts', fn (Blueprint $t) => $t->dropColumn('scopes'));
        }
    }
};
