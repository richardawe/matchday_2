<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radio_bulletins', function (Blueprint $table) {
            $table->json('audio_cues')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('radio_bulletins', function (Blueprint $table) {
            $table->dropColumn('audio_cues');
        });
    }
};
