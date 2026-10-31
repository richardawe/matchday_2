<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// One-off for the matches still marked live hours after kick-off. The scheduler
// closes new ones; this clears the backlog on deploy. Scores are kept and each
// row is flagged so prediction scoring waits for a feed to confirm the result.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('matches')) {
            return;
        }
        app(\App\Services\StaleMatchCloser::class)->close();
    }

    public function down(): void
    {
        // Not reversed: putting these matches back to "live" would undo real full-time results.
    }
};
