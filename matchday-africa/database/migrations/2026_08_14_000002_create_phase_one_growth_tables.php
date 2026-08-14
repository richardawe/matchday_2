<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('prediction_groups', function (Blueprint $table) {
            $table->id(); $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name'); $table->string('code', 10)->unique(); $table->boolean('is_public')->default(false); $table->timestamps();
        });
        Schema::create('prediction_group_members', function (Blueprint $table) {
            $table->id(); $table->foreignId('prediction_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->timestamps();
            $table->unique(['prediction_group_id','user_id']);
        });
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('match_alerts')->default(true); $table->boolean('prediction_reminders')->default(true);
            $table->boolean('weekly_digest')->default(true); $table->string('digest_day', 12)->default('friday'); $table->timestamps();
        });
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('visitor_id')->nullable()->index(); $table->string('event', 80)->index();
            $table->string('path', 500)->nullable(); $table->json('properties')->nullable(); $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('analytics_events'); Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('prediction_group_members'); Schema::dropIfExists('prediction_groups');
    }
};
