<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NotificationPreference extends Model {
    protected $fillable=['user_id','match_alerts','prediction_reminders','weekly_digest','digest_day'];
    protected $casts=['match_alerts'=>'boolean','prediction_reminders'=>'boolean','weekly_digest'=>'boolean'];
    public function user(){return $this->belongsTo(User::class);}
}
