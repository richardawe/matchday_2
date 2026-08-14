<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->integer('football_data_id')->nullable();
            $table->string('name');
            $table->string('position')->nullable(); // GK, DEF, MID, FWD
            $table->string('detailed_position')->nullable(); // CB, LB, RB, CDM, CAM, LW, RW, ST, etc.
            $table->integer('shirt_number')->nullable();
            $table->string('nationality')->nullable();
            $table->string('nationality_code', 3)->nullable(); // ISO 3-letter code
            $table->date('date_of_birth')->nullable();
            $table->integer('age')->nullable();
            $table->string('photo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_captain')->default(false);
            $table->boolean('is_vice_captain')->default(false);
            $table->decimal('height', 5, 2)->nullable(); // in cm
            $table->decimal('weight', 5, 2)->nullable(); // in kg
            $table->string('preferred_foot')->nullable(); // left, right, both
            $table->integer('market_value')->nullable(); // in euros
            $table->date('contract_until')->nullable();
            $table->timestamp('last_api_update')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['team_id']);
            $table->index(['position']);
            $table->index(['shirt_number', 'team_id']);
            $table->index(['football_data_id']);
            $table->unique(['team_id', 'shirt_number']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('players');
    }
};
