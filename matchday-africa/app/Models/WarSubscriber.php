<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WarSubscriber extends Model { protected $guarded=[]; protected $casts=['consented_at'=>'datetime','unsubscribed_at'=>'datetime']; }
