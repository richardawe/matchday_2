<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AnalyticsEvent extends Model {protected $fillable=['user_id','visitor_id','event','path','properties'];protected $casts=['properties'=>'array'];}
