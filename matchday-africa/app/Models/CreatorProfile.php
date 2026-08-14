<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class CreatorProfile extends Model {protected $fillable=['user_id','display_name','slug','bio','speciality','status','social_url'];public function user(){return $this->belongsTo(User::class);}public function blogs(){return $this->hasMany(Blog::class);}public function earnings(){return $this->hasMany(CreatorEarning::class);} }
