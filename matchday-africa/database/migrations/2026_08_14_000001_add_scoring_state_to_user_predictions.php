<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_predictions', 'is_scored')) {
            Schema::table('user_predictions', function (Blueprint $table) {
                $table->boolean('is_scored')->default(false)->after('is_correct')->index();
            });
        }

        DB::table('user_predictions')
            ->whereNotNull('is_correct')
            ->update(['is_scored' => true]);

        // Older code automatically failed every goalscorer prediction.
        DB::table('user_predictions')
            ->where('prediction_type', 'goalscorer')
            ->update(['is_correct' => null, 'is_scored' => false, 'points_earned' => 0]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_predictions', 'is_scored')) {
            Schema::table('user_predictions', fn (Blueprint $table) => $table->dropColumn('is_scored'));
        }
    }
};
