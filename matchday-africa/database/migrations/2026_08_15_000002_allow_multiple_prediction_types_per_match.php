<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_set_matches', function (Blueprint $table) {
            $table->dropUnique(['prediction_set_id', 'match_id']);
            $table->unique(['prediction_set_id', 'match_id', 'prediction_type'], 'prediction_round_match_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('prediction_set_matches', function (Blueprint $table) {
            $table->dropUnique('prediction_round_match_type_unique');
            $table->unique(['prediction_set_id', 'match_id']);
        });
    }
};
