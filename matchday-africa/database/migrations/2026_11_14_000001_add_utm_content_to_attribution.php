<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-touch utm_content (the post, match, slideshow or player id SocialLink adds to outbound links)
 * on accounts and email signups. Existing rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users' => 'utm_campaign', 'email_subscribers' => 'utm_campaign'] as $table => $after) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'utm_content')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('utm_content', 150)->nullable()->after($after));
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'email_subscribers'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'utm_content')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('utm_content'));
            }
        }
    }
};
