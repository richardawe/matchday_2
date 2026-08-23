<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class MatchPrizeAward extends Model{protected $guarded=[];protected $casts=['contacted_at'=>'datetime','claimed_at'=>'datetime','fulfilled_at'=>'datetime'];public function sponsor(){return$this->belongsTo(MatchSponsor::class,'match_sponsor_id');}public function prediction(){return$this->belongsTo(UserPrediction::class,'user_prediction_id');}public function user(){return$this->belongsTo(User::class);} }
