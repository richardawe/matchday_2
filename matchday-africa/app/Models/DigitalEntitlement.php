<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DigitalEntitlement extends Model { protected $guarded=[]; protected $casts=['granted_at'=>'datetime']; public function product(){return $this->belongsTo(CommerceProduct::class,'commerce_product_id');} }
