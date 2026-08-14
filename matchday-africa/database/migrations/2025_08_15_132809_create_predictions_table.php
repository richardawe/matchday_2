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
        Schema::create('prediction_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['draft', 'active', 'closed', 'archived'])->default('draft');
            $table->datetime('prediction_deadline');
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'prediction_deadline']);
            $table->index(['admin_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prediction_sets');
    }
};
