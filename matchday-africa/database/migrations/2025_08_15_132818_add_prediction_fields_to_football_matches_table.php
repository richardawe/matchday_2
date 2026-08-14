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
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('is_prediction_eligible')->default(false);
            $table->datetime('prediction_deadline')->nullable();
            $table->json('prediction_types_enabled')->nullable();
            
            $table->index(['is_prediction_eligible', 'match_date']);
            $table->index('prediction_deadline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex(['is_prediction_eligible', 'match_date']);
            $table->dropIndex('prediction_deadline');
            $table->dropColumn(['is_prediction_eligible', 'prediction_deadline', 'prediction_types_enabled']);
        });
    }
};
