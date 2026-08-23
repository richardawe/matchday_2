<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('statsbomb_player_matches')) {
            Schema::create('statsbomb_player_matches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('statsbomb_match_id');
                $table->unsignedBigInteger('statsbomb_player_id');
                $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
                $table->string('player_name');
                $table->string('nickname')->nullable();
                $table->string('country')->nullable();
                $table->string('team');
                $table->string('opponent');
                $table->date('match_date');
                $table->string('competition');
                $table->string('season');
                $table->boolean('started')->default(false);
                $table->unsignedSmallInteger('minutes')->nullable();
                foreach (['goals','assists','shots','shots_on_target','passes','completed_passes','key_passes','tackles','interceptions','yellow_cards','red_cards'] as $column) {
                    $table->unsignedSmallInteger($column)->default(0);
                }
                $table->json('raw_stats')->nullable();
                $table->timestamps();
            });
        }

        $indexNames = collect(Schema::getIndexes('statsbomb_player_matches'))->pluck('name');
        if (!$indexNames->contains('sb_player_match_unique')) {
            Schema::table('statsbomb_player_matches', fn (Blueprint $table) =>
                $table->unique(['statsbomb_match_id', 'statsbomb_player_id'], 'sb_player_match_unique')
            );
        }
        if (!$indexNames->contains('sb_player_name_date_idx')) {
            Schema::table('statsbomb_player_matches', fn (Blueprint $table) =>
                $table->index(['player_name', 'match_date'], 'sb_player_name_date_idx')
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statsbomb_player_matches');
    }
};
