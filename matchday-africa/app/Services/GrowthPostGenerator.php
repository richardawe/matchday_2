<?php
namespace App\Services;
use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\GrowthPost;
use Illuminate\Support\Str;
class GrowthPostGenerator {
    public function generate(): int {
        $posts=collect();
        FootballMatch::with(['homeTeam','awayTeam','league'])->whereBetween('match_date',[now(),now()->addDays(2)])->orderBy('match_date')->limit(8)->get()->each(function($match)use($posts){
            $text="{$match->homeTeam?->name} v {$match->awayTeam?->name}\n".($match->league?->name?:'Football')." · {$match->match_date->format('D H:i')}\n\nPreview, stats and predictions: ".route('matches.show',$match)."\n\n#MatchdayAfrica #Football";
            $posts->push(['type'=>'match','text'=>$text,'at'=>$match->match_date->copy()->subHours(2),'image'=>data_get($match->war_home,'image')?:asset('images/social-default.png'),'url'=>route('matches.show',$match)]);
        });
        Blog::published()->where('published_at','>=',now()->subDays(3))->latest('published_at')->limit(3)->get()->each(function($blog)use($posts){
            $posts->push(['type'=>'news','text'=>Str::limit("NEW: {$blog->title}\n\n{$blog->excerpt}\n\n".route('blogs.show',$blog)."\n\n#FootballNews #MatchdayAfrica",275,'…'),'at'=>now(),'image'=>$blog->featured_image_url,'url'=>route('blogs.show',$blog)]);
        });
        $created=0;
        foreach($posts as $post){$key=sha1($post['text']);if(GrowthPost::where('content_key',$key)->exists())continue;GrowthPost::create(['content_key'=>$key,'type'=>$post['type'],'message'=>$post['text'],'image_url'=>$post['image'],'destination_url'=>$post['url'],'status'=>'draft','scheduled_at'=>$post['at']->isPast()?now():$post['at']]);$created++;}
        return $created;
    }
}
