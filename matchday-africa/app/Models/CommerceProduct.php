<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CommerceProduct extends Model { protected $guarded=[]; protected $casts=['active'=>'boolean','metadata'=>'array']; public function scopeActive($q){return $q->where('active',true);} }
