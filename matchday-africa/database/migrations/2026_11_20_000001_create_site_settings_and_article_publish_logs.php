<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * site_settings: switches an admin flips at /admin/settings (first one: agent_can_publish).
 * article_publish_logs: one row per publish or unpublish of an article, by the agent or an editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('article_publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->string('action', 12); // publish | unpublish
            $table->string('actor', 10);  // agent | editor
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['blog_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_publish_logs');
        Schema::dropIfExists('site_settings');
    }
};
