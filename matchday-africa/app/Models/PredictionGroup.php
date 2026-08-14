<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PredictionGroup extends Model {
    protected $fillable=['owner_id','name','code','is_public']; protected $casts=['is_public'=>'boolean'];
    public function owner(){return $this->belongsTo(User::class,'owner_id');}
    public function members(){return $this->belongsToMany(User::class,'prediction_group_members')->withTimestamps();}
}
