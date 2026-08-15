<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SyncRun extends Model {
    protected $fillable=['task','status','started_at','finished_at','records_processed','errors','message','context'];
    protected $casts=['started_at'=>'datetime','finished_at'=>'datetime','context'=>'array'];
}
