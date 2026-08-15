<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NewsCandidate extends Model {
    protected $fillable=['source','source_guid','source_url','title','summary','source_published_at','fingerprint','selection_score','status','blog_id','failure_reason'];
    protected $casts=['source_published_at'=>'datetime'];
    public function blog(){return $this->belongsTo(Blog::class);}
}
