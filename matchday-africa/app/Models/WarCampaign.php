<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WarCampaign extends Model { protected $guarded=[]; protected $casts=['approved_at'=>'datetime']; public function match(){return $this->belongsTo(FootballMatch::class,'match_id');} }
