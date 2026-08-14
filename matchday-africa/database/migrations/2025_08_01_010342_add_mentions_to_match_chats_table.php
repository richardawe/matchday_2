<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('match_chats', function (Blueprint $table) {
            $table->json('mentioned_users')->nullable()->after('is_gif');
            $table->index('mentioned_users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('match_chats', function (Blueprint $table) {
            $table->dropIndex(['mentioned_users']);
            $table->dropColumn('mentioned_users');
        });
    }
};
