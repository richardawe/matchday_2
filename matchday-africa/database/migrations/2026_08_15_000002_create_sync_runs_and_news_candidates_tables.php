<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task')->index();
            $table->string('status')->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
            $table->index(['task', 'started_at']);
        });

        Schema::create('news_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('source_guid')->nullable();
            $table->string('source_url', 1000);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->timestamp('source_published_at')->nullable();
            $table->string('fingerprint', 64)->unique();
            $table->integer('selection_score')->default(0);
            $table->string('status')->default('discovered')->index();
            $table->foreignId('blog_id')->nullable()->constrained('blogs')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_candidates');
        Schema::dropIfExists('sync_runs');
    }
};
