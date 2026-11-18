<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The breaking news spot: one short headline at the top of the homepage, match and listen pages. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breaking_news', function (Blueprint $table) {
            $table->id();
            $table->string('headline', 90);
            $table->string('body', 280)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('image_credit', 200)->nullable();
            $table->string('source', 10)->default('admin'); // agent (the API) or admin (the admin page)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['ended_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breaking_news');
    }
};
