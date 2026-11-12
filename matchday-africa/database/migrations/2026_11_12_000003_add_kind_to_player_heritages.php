<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A heritage row is either descent ("of Nigerian descent") or, for a sourced senior African
 * international whose stored nationality is another country (a provider lists Aaron Wan-Bissaka as
 * English), international: the page then names the country he plays for, never "descent".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('player_heritages') && !Schema::hasColumn('player_heritages', 'kind')) {
            Schema::table('player_heritages', function (Blueprint $table) {
                $table->string('kind', 16)->default('descent')->after('country_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('player_heritages', 'kind')) {
            Schema::table('player_heritages', fn (Blueprint $table) => $table->dropColumn('kind'));
        }
    }
};
