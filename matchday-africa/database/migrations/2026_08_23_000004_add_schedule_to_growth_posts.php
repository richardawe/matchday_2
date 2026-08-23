<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('growth_posts', function (Blueprint $table) { $table->timestamp('scheduled_at')->nullable()->after('status')->index(); $table->text('image_url')->nullable(); $table->text('destination_url')->nullable(); }); }
    public function down(): void { Schema::table('growth_posts', fn (Blueprint $table) => $table->dropColumn(['scheduled_at','image_url','destination_url'])); }
};
