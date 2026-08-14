<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CreatorEarning extends Model { protected $guarded=[]; protected $casts=['paid_at'=>'datetime']; public function creator(){return $this->belongsTo(CreatorProfile::class,'creator_profile_id');} }
