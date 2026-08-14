<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CommerceOrder extends Model { protected $guarded=[]; protected $casts=['shipping'=>'array','paid_at'=>'datetime']; public function user(){return $this->belongsTo(User::class);} public function creator(){return $this->belongsTo(CreatorProfile::class,'creator_profile_id');} public function items(){return $this->hasMany(CommerceOrderItem::class);} }
