<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The live Griot timeline. match_plays holds every play of a match in one standard shape,
// whichever provider sent it (see config/griot.php); griot_posts holds what the Griot chose to say.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('match_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_key', 64);
            $table->unsignedInteger('seq');
            $table->unsignedTinyInteger('period')->default(1);
            $table->unsignedSmallInteger('minute')->default(0);
            $table->unsignedSmallInteger('extra_minute')->nullable();
            $table->string('type', 24);
            $table->string('side', 4)->nullable();
            $table->string('player')->nullable();
            $table->string('player2')->nullable();
            $table->decimal('x', 5, 1)->nullable();
            $table->decimal('y', 5, 1)->nullable();
            $table->json('detail')->nullable();
            // The provider's own words: used to read the play, never published.
            $table->text('text')->nullable();
            $table->timestamp('happened_at')->nullable();
            $table->timestamps();
            $table->unique(['match_id', 'provider', 'provider_key']);
            $table->index(['match_id', 'seq']);
        });

        Schema::create('griot_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->string('key', 120)->unique();
            $table->string('kind', 20);
            $table->string('side', 4)->nullable();
            $table->unsignedTinyInteger('period')->default(1);
            $table->unsignedSmallInteger('minute')->default(0);
            $table->unsignedSmallInteger('extra_minute')->nullable();
            $table->unsignedInteger('seq');
            $table->unsignedSmallInteger('home_score')->default(0);
            $table->unsignedSmallInteger('away_score')->default(0);
            // Names and numbers the caption may use, and the plays it came from.
            $table->json('facts');
            $table->json('play_keys');
            $table->decimal('confidence', 4, 3)->default(1);
            $table->string('picker', 20)->default('rules');
            // Written by the small model after the fact check; null means the Griot templates speak.
            $table->json('written')->nullable();
            $table->string('audio_path')->nullable();
            $table->timestamps();
            $table->index(['match_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('griot_posts');
        Schema::dropIfExists('match_plays');
    }
};
