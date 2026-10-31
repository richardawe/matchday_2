<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Get weekend fixtures + results" signups from the footer, with where each one came from.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_subscribers')) {
            return;
        }
        Schema::create('email_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('list', 40)->default('weekend');
            $table->string('source')->nullable();
            $table->string('utm_source', 100)->nullable()->index();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamp('consented_at');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_subscribers');
    }
};
