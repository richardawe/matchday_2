<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// In-app inbox and web push. `notifications` is Laravel's own table: the prediction notifications
// already wrote to it, but it was never created, so those inbox entries failed until now.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
        // One row per browser a signed-in user allowed to receive push.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 120);
            $table->string('auth', 40);
            $table->string('user_agent', 255)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
        // "Alert me" on a match.
        Schema::create('match_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'match_id']);
        });
        // What has been announced for each match (kick-off, score:1-0, red:…), so each goes out once.
        Schema::create('match_alert_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('key', 60);
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
            $table->unique(['match_id', 'key']);
        });
        // Messages from Matchday Africa to users (admin, /admin/messages).
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('url', 500)->nullable();
            $table->string('audience', 30);
            $table->boolean('pushed')->default(false);
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('match_alert_log');
        Schema::dropIfExists('match_alerts');
        Schema::dropIfExists('push_subscriptions');
    }
};
