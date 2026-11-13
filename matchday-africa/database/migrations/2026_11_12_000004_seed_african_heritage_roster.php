<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds heritage rows once from the owner's sourced roster (players_roster.md, checked 12 Nov 2026,
 * converted to database/seeders/data/african_heritage_epl.json). to_verify rows go in pending. As a
 * migration it runs once per database, so later editor changes are never re-imported over; run
 * php artisan players:heritage-import again by hand after the squads sync if names were unmatched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('player_heritages') || !is_file(database_path('seeders/data/african_heritage_epl.json'))
            || app()->runningUnitTests()) {
            return;
        }
        Artisan::call('players:heritage-import');
        Log::info('African heritage roster imported', ['output' => Artisan::output()]);
    }

    public function down(): void
    {
        // Heritage rows may have been edited since; leave them.
    }
};
