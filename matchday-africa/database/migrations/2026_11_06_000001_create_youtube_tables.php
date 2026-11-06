<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded: the first production run created youtube_accounts and then failed on
        // youtube_broadcasts (MySQL rejects a second NOT NULL timestamp without a default).
        // The connected channel (one row). Tokens and the stream key are encrypted with APP_KEY.
        if (!Schema::hasTable('youtube_accounts')) Schema::create('youtube_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('channel_id', 64);
            $t->string('channel_title')->nullable();
            $t->text('refresh_token');
            $t->text('access_token')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('stream_id', 64)->nullable();
            $t->text('stream_key')->nullable();
            $t->string('ingest_url')->nullable();
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        if (!Schema::hasTable('youtube_broadcasts')) Schema::create('youtube_broadcasts', function (Blueprint $t) {
            $t->id();
            $t->string('broadcast_id', 64)->unique();
            // "2026-11-07" for a matchday, "test-…" for a test broadcast.
            $t->string('window_key', 40)->unique();
            $t->string('title');
            // dateTime, not timestamp: MySQL in strict mode gives a second NOT NULL timestamp an invalid default.
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('privacy', 10);
            $t->string('status', 20)->default('scheduled'); // scheduled, live, complete, failed
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_broadcasts');
        Schema::dropIfExists('youtube_accounts');
    }
};
