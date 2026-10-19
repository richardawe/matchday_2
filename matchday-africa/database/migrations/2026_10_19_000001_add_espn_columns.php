<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ESPN-only fixtures (CAF competitions, African domestic leagues) have no football-data.org IDs,
// so those references become nullable and each table gains its ESPN key.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->bigInteger('football_data_id')->nullable()->change();
            $table->string('espn_slug', 40)->nullable()->unique();
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->bigInteger('football_data_id')->nullable()->change();
            $table->bigInteger('espn_id')->nullable()->unique();
        });
        Schema::table('matches', function (Blueprint $table) {
            $table->bigInteger('football_data_id')->nullable()->change();
            $table->bigInteger('league_football_data_id')->nullable()->change();
            $table->bigInteger('home_team_football_data_id')->nullable()->change();
            $table->bigInteger('away_team_football_data_id')->nullable()->change();
            $table->bigInteger('espn_id')->nullable()->unique();
            $table->string('data_source', 20)->nullable();
            $table->string('events_source', 20)->nullable();
            $table->timestamp('espn_synced_at')->nullable()->index();
        });
        Schema::table('match_events', function (Blueprint $table) {
            $table->bigInteger('match_football_data_id')->nullable()->change();
            $table->bigInteger('team_football_data_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropUnique(['espn_id']);
            $table->dropIndex(['espn_synced_at']);
            $table->dropColumn(['espn_id', 'data_source', 'events_source', 'espn_synced_at']);
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['espn_id']);
            $table->dropColumn('espn_id');
        });
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropUnique(['espn_slug']);
            $table->dropColumn('espn_slug');
        });
    }
};
