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
        if (! Schema::hasTable('matches') || Schema::hasColumn('matches', 'has_preview')) {
            return;
        }

        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('has_preview')->default(false)->after('has_live_coverage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('matches') || ! Schema::hasColumn('matches', 'has_preview')) {
            return;
        }

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('has_preview');
        });
    }
}; 
