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
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('football_data_id')->unique()->index();
            $table->string('name');
            $table->string('short_code', 10)->nullable();
            $table->string('type')->nullable(); // cup, league, etc.
            $table->string('country_name')->nullable();
            $table->string('country_code', 3)->nullable();
            $table->string('logo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false); // For highlighting important leagues
            $table->integer('priority')->default(1000); // For sorting leagues
            $table->json('metadata')->nullable(); // For additional API data
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['country_code', 'is_active']);
            $table->index(['is_featured', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
