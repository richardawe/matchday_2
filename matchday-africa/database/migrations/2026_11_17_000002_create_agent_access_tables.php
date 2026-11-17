<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The agent role's access: long-lived API tokens (only a SHA-256 hash is stored; the token is shown
 * once), an audit log of every agent API call, and daily visit counts by UTM tags for /api/agent/analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        // users.role was enum('user', 'admin'): add 'agent'.
        if (Schema::hasColumn('users', 'role')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE users MODIFY role ENUM('user', 'admin', 'agent') NOT NULL DEFAULT 'user'");
            } else {
                Schema::table('users', fn (Blueprint $t) => $t->enum('role', ['user', 'admin', 'agent'])->default('user')->change());
            }
        }
        if (!Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('name', 100);
                $t->string('token_hash', 64)->unique();
                $t->string('prefix', 12); // the first characters, to tell tokens apart on the admin page
                $t->timestamp('last_used_at')->nullable();
                $t->string('last_used_ip', 45)->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('agent_audit_logs')) {
            Schema::create('agent_audit_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->unsignedBigInteger('api_token_id')->nullable()->index();
                $t->string('channel', 8)->default('api'); // api, web
                $t->string('method', 8);
                $t->string('path', 255);
                $t->string('route', 100)->nullable();
                $t->unsignedSmallInteger('status');
                $t->string('ip', 45)->nullable();
                $t->json('params')->nullable(); // query keys and body field names (values only for ids and filters)
                $t->unsignedInteger('duration_ms')->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }
        if (!Schema::hasTable('utm_visits')) {
            Schema::create('utm_visits', function (Blueprint $t) {
                $t->id();
                $t->date('day');
                $t->string('utm_source', 100);
                $t->string('utm_medium', 100)->default('');
                $t->string('utm_campaign', 150)->default('');
                $t->unsignedInteger('visits')->default(0);
                $t->timestamps();
                $t->unique(['day', 'utm_source', 'utm_medium', 'utm_campaign'], 'utm_visits_day_tags_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('utm_visits');
        Schema::dropIfExists('agent_audit_logs');
        Schema::dropIfExists('api_tokens');
        // users.role keeps 'agent' allowed: narrowing the enum would fail while an agent account exists.
    }
};
