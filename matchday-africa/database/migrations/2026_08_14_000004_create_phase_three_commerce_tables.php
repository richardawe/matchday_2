<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('subscription_status')->nullable();
            $table->timestamp('premium_until')->nullable();
        });

        Schema::create('commerce_products', function (Blueprint $table) {
            $table->id(); $table->string('slug')->unique(); $table->string('name');
            $table->text('description'); $table->string('type');
            $table->unsignedInteger('price'); $table->string('currency', 3)->default('gbp');
            $table->string('image')->nullable(); $table->string('download_path')->nullable();
            $table->unsignedInteger('stock')->nullable(); $table->boolean('active')->default(true);
            $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email'); $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable(); $table->string('status')->default('pending')->index();
            $table->unsignedInteger('total'); $table->string('currency', 3)->default('gbp');
            $table->json('shipping')->nullable(); $table->timestamp('paid_at')->nullable(); $table->timestamps();
        });
        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->id(); $table->foreignId('commerce_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name'); $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_amount'); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('digital_entitlements', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_order_id')->constrained()->cascadeOnDelete();
            $table->timestamp('granted_at'); $table->timestamps();
            $table->unique(['user_id','commerce_product_id']);
        });
        Schema::create('sponsor_placements', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slot')->index();
            $table->string('headline'); $table->string('destination_url'); $table->string('image_url')->nullable();
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true); $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0); $table->timestamps();
        });
        Schema::create('creator_earnings', function (Blueprint $table) {
            $table->id(); $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount'); $table->string('currency', 3)->default('gbp');
            $table->string('source')->default('referral'); $table->string('status')->default('pending')->index();
            $table->timestamp('paid_at')->nullable(); $table->timestamps();
        });

        $now = now();
        DB::table('commerce_products')->insert([
            ['slug'=>'premium-monthly','name'=>'Matchday Africa Premium','description'=>'Ad-light access, advanced match intelligence, exclusive War drops and a monthly digital card.','type'=>'membership','price'=>399,'currency'=>'gbp','image'=>'/war/downloads/rights-safe/roman-legion.png','download_path'=>null,'stock'=>null,'active'=>1,'metadata'=>json_encode(['interval'=>'month']),'created_at'=>$now,'updated_at'=>$now],
            ['slug'=>'warrior-wallpaper-pack','name'=>'Warrior Wallpaper Pack','description'=>'Twenty rights-safe mobile wallpapers with no club names or protected badges.','type'=>'digital','price'=>249,'currency'=>'gbp','image'=>'/war/downloads/rights-safe/byzantine-cataphract.png','download_path'=>'war/downloads/rights-safe','stock'=>null,'active'=>1,'metadata'=>json_encode(['format'=>'PNG collection']),'created_at'=>$now,'updated_at'=>$now],
            ['slug'=>'founders-card-set','name'=>'Founders Digital Card Set','description'=>'A collectible launch-season set of rights-safe warrior cards.','type'=>'digital','price'=>499,'currency'=>'gbp','image'=>'/war/downloads/rights-safe/numidian-cavalry.png','download_path'=>'war/downloads/rights-safe','stock'=>null,'active'=>1,'metadata'=>json_encode(['format'=>'PNG collection']),'created_at'=>$now,'updated_at'=>$now],
            ['slug'=>'war-council-art-print','name'=>'War Council Art Print','description'=>'Museum-grade A3 print featuring original, rights-safe Matchday warrior art.','type'=>'physical','price'=>1800,'currency'=>'gbp','image'=>'/war/downloads/rights-safe/spartan-hoplite.png','download_path'=>null,'stock'=>100,'active'=>1,'metadata'=>json_encode(['shipping'=>true]),'created_at'=>$now,'updated_at'=>$now],
            ['slug'=>'matchday-legion-shirt','name'=>'Matchday Legion Shirt','description'=>'Heavyweight black supporter shirt using original Matchday insignia only.','type'=>'physical','price'=>2400,'currency'=>'gbp','image'=>'/war/downloads/rights-safe/anglo-saxon-housecarl.png','download_path'=>null,'stock'=>100,'active'=>1,'metadata'=>json_encode(['shipping'=>true]),'created_at'=>$now,'updated_at'=>$now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_earnings'); Schema::dropIfExists('sponsor_placements');
        Schema::dropIfExists('digital_entitlements'); Schema::dropIfExists('commerce_order_items');
        Schema::dropIfExists('commerce_orders'); Schema::dropIfExists('commerce_products');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['stripe_customer_id','stripe_subscription_id','subscription_status','premium_until']));
    }
};
