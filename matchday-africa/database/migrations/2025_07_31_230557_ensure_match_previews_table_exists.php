<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('matches') || Schema::hasTable('match_previews')) {
            return;
        }

        Schema::create('match_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->longText('preview_content');
            $table->string('ai_model_used')->nullable();
            $table->timestamp('generated_at')->nullable()->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->integer('view_count')->default(0);
            $table->enum('generation_status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_previews');
    }
};
