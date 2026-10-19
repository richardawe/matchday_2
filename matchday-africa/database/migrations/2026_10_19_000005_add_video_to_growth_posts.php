<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Full-time reels wait in growth_posts for a free slot like every other post, so they need a video.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('growth_posts', 'video_url')) {
            Schema::table('growth_posts', fn (Blueprint $table) => $table->text('video_url')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('growth_posts', fn (Blueprint $table) => $table->dropColumn('video_url'));
    }
};
