<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WarReferral extends Model { public $incrementing=false; protected $keyType='string'; protected $guarded=[]; protected $casts=['landed_at'=>'datetime','challenge_at'=>'datetime','joined_at'=>'datetime','completed_at'=>'datetime']; }
