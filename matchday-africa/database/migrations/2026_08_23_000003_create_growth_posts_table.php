<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('growth_posts', function (Blueprint $table) {
            $table->id();
            $table->string('content_key', 64)->unique();
            $table->string('type', 40);
            $table->text('message');
            $table->string('provider_post_id')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_posts');
    }
};
