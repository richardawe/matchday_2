<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('war_factions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->unique()->constrained('teams')->nullOnDelete();
            $table->string('club_name');
            $table->string('faction_name');
            $table->string('trait');
            $table->string('image');
            $table->string('colour', 16)->default('#d6a64d');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('war_rooms', function (Blueprint $table) {
            $table->string('code', 8)->primary();
            $table->uuid('host_token');
            $table->uuid('guest_token')->nullable();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guest_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('host_team');
            $table->unsignedSmallInteger('guest_team')->default(1);
            $table->json('state');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('war_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('channel', 24);
            $table->text('caption');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamps();
            $table->unique(['match_id', 'kind', 'channel']);
        });

        Schema::create('war_referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->string('source', 40);
            $table->string('campaign')->nullable();
            $table->timestamp('landed_at')->nullable();
            $table->timestamp('challenge_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('war_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('consented_at');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('war_subscribers');
        Schema::dropIfExists('war_referrals');
        Schema::dropIfExists('war_campaigns');
        Schema::dropIfExists('war_rooms');
        Schema::dropIfExists('war_factions');
    }
};
