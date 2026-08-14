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
        if (! Schema::hasTable('matches') || Schema::hasTable('match_previews')) {
            return;
        }

        Schema::create('match_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->longText('preview_content');
            $table->string('ai_model_used')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('view_count')->default(0);
            $table->enum('generation_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['match_id']);
            $table->index(['is_featured']);
            $table->index(['generation_status']);
            $table->index(['generated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_previews');
    }
}; 
