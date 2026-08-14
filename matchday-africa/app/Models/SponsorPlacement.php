<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SponsorPlacement extends Model { protected $guarded=[]; protected $casts=['active'=>'boolean','starts_at'=>'datetime','ends_at'=>'datetime']; public function scopeLive($q,$slot=null){$q->where('active',true)->where(fn($x)=>$x->whereNull('starts_at')->orWhere('starts_at','<=',now()))->where(fn($x)=>$x->whereNull('ends_at')->orWhere('ends_at','>=',now())); return $slot?$q->where('slot',$slot):$q;} }
