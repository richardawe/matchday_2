<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where each signup came from: the first-touch UTM tags (App\Support\Attribution) plus the
// referring site and landing page, written once when the account is created.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'utm_source')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('utm_source', 100)->nullable()->index();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('signup_referrer')->nullable();
            $table->string('signup_landing')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'utm_source')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['utm_source']);
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'signup_referrer', 'signup_landing']);
        });
    }
};
