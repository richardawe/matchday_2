<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What has been sent to Buffer, so each card or reel goes to each channel once.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 60);
            $table->string('channel', 20);
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->string('status', 12);
            $table->string('buffer_post_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['subject', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
