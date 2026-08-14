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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('football_data_id')->unique()->index();
            $table->string('name');
            $table->string('short_code', 10)->nullable();
            $table->string('common_name')->nullable(); // Alternative name
            $table->string('country_name')->nullable();
            $table->string('country_code', 3)->nullable();
            $table->string('logo_url')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('venue_city')->nullable();
            $table->year('founded_year')->nullable();
            $table->string('primary_color', 7)->nullable(); // Hex color code
            $table->string('secondary_color', 7)->nullable(); // Hex color code
            $table->boolean('is_active')->default(true);
            $table->boolean('is_national_team')->default(false);
            $table->json('metadata')->nullable(); // For additional API data
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['country_code', 'is_active']);
            $table->index(['name', 'is_active']);
            $table->index('is_national_team');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
