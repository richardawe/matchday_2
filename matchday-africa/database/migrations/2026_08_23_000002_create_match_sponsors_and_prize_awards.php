<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{
 public function up():void{
  if(!Schema::hasColumn('user_predictions','home_score_prediction'))Schema::table('user_predictions',fn(Blueprint $t)=>$t->unsignedSmallInteger('home_score_prediction')->nullable());
  if(!Schema::hasColumn('user_predictions','away_score_prediction'))Schema::table('user_predictions',fn(Blueprint $t)=>$t->unsignedSmallInteger('away_score_prediction')->nullable());
  if(!Schema::hasTable('match_sponsors'))Schema::create('match_sponsors',function(Blueprint $t){$t->id();$t->foreignId('match_id')->unique()->constrained('matches')->cascadeOnDelete();$t->string('name');$t->string('logo_path')->nullable();$t->string('website_url')->nullable();$t->string('prize_title');$t->string('prize_value')->nullable();$t->unsignedSmallInteger('prize_quantity')->default(1);$t->text('terms')->nullable();$t->dateTime('claim_deadline')->nullable();$t->boolean('active')->default(true);$t->foreignId('created_by')->constrained('users');$t->timestamps();});
  if(!Schema::hasTable('match_prize_awards'))Schema::create('match_prize_awards',function(Blueprint $t){$t->id();$t->foreignId('match_sponsor_id')->constrained()->cascadeOnDelete();$t->foreignId('user_prediction_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('status')->default('selected');$t->dateTime('contacted_at')->nullable();$t->dateTime('claimed_at')->nullable();$t->dateTime('fulfilled_at')->nullable();$t->timestamps();$t->unique(['match_sponsor_id','user_id']);});
 }
 public function down():void{Schema::dropIfExists('match_prize_awards');Schema::dropIfExists('match_sponsors');if(Schema::hasColumn('user_predictions','home_score_prediction'))Schema::table('user_predictions',fn(Blueprint $t)=>$t->dropColumn('home_score_prediction'));if(Schema::hasColumn('user_predictions','away_score_prediction'))Schema::table('user_predictions',fn(Blueprint $t)=>$t->dropColumn('away_score_prediction'));}
};
