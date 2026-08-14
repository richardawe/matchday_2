<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CommerceOrderItem extends Model { protected $guarded=[]; protected $casts=['metadata'=>'array']; public function product(){return $this->belongsTo(CommerceProduct::class,'commerce_product_id');} }
