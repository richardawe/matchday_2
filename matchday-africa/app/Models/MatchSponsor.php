<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class MatchSponsor extends Model{protected $guarded=[];protected $casts=['active'=>'boolean','claim_deadline'=>'datetime'];public function match(){return$this->belongsTo(FootballMatch::class,'match_id');}public function awards(){return$this->hasMany(MatchPrizeAward::class);} }
