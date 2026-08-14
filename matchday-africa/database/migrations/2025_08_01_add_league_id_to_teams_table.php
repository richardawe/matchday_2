<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('teams', function (Blueprint $table) {
            if (!Schema::hasColumn('teams', 'league_id')) {
                $table->unsignedBigInteger('league_id')->nullable()->after('football_data_id');
                $table->index('league_id');
            }
        });
    }

    public function down()
    {
        Schema::table('teams', function (Blueprint $table) {
            if (Schema::hasColumn('teams', 'league_id')) {
                $table->dropIndex(['league_id']);
                $table->dropColumn('league_id');
            }
        });
    }
};
