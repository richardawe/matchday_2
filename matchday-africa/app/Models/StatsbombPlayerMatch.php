<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class StatsbombPlayerMatch extends Model{protected $guarded=[];protected $casts=['match_date'=>'date','started'=>'boolean','raw_stats'=>'array'];public function player(){return $this->belongsTo(Player::class);}}
