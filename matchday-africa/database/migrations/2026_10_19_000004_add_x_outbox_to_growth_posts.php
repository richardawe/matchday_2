<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// growth_posts becomes the social outbox that buffer:publish sends from, one row per subject and
// channel; buffer_usage counts API requests per month against the Buffer plan's limit. Safe to re-run after a partial failure.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('growth_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('growth_posts', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
            if (!Schema::hasColumn('growth_posts', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0);
            }
            if (!Schema::hasColumn('growth_posts', 'channel')) {
                $table->string('channel', 20)->default('x');
            }
        });

        if (!Schema::hasTable('buffer_usage')) {
            Schema::create('buffer_usage', function (Blueprint $table) {
                $table->string('month', 6)->primary();
                $table->unsignedInteger('requests')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('buffer_usage');
        Schema::table('growth_posts', fn (Blueprint $table) => $table->dropColumn(['expires_at', 'attempts', 'channel']));
    }
};
