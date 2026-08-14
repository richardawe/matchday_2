<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class MatchStory extends Model {protected $fillable=['match_id','headline','story','beats','generated_at'];protected $casts=['beats'=>'array','generated_at'=>'datetime'];public function match(){return $this->belongsTo(FootballMatch::class,'match_id');}}
