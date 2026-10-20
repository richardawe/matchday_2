<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radio_bulletins', function (Blueprint $table) {
            $table->id();
            $table->string('slot')->unique();
            $table->string('title');
            $table->string('status')->default('queued')->index();
            $table->string('stage')->default('script');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('facts')->nullable();
            $table->json('segments')->nullable();
            $table->text('script')->nullable();
            $table->string('voice')->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('audio_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radio_bulletins');
    }
};
