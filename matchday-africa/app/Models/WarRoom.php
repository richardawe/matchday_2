<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WarRoom extends Model { protected $primaryKey='code'; public $incrementing=false; protected $keyType='string'; protected $guarded=[]; protected $casts=['state'=>'array','expires_at'=>'datetime']; }
