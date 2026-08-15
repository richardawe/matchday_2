<?php
namespace App\Services;
use App\Models\Blog;
use App\Models\NewsCandidate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FootballNewsService {
    public function __construct(private OpenRouterService $editor){}

    public function publish(int $limit=1): array {
        $discovered=$this->discover();
        $alreadyPublished=NewsCandidate::where('status','published')->whereDate('updated_at',now())->count();
        $limit=min(max(0,2-$alreadyPublished),max(1,min($limit,2)));
        if($limit===0)return ['success'=>0,'errors'=>0,'message'=>'Daily target of two football articles is already complete','discovered'=>$discovered];
        $published=0;$errors=0;
        $candidates=NewsCandidate::where('status','discovered')->where('source_published_at','>=',now()->subHours(config('news.max_age_hours',36)))->orderByDesc('selection_score')->orderByDesc('source_published_at')->limit(max(1,min($limit,2)))->get();
        foreach($candidates as $candidate){
            try { $this->turnIntoPost($candidate); $published++; }
            catch(\Throwable $e){$errors++;$candidate->update(['status'=>'failed','failure_reason'=>Str::limit($e->getMessage(),1000)]);}
        }
        return ['success'=>$published,'errors'=>$errors,'discovered'=>$discovered,'eligible'=>$candidates->count(),'message'=>"Discovered {$discovered}; eligible {$candidates->count()}; published {$published}; failed {$errors}"];
    }

    public function discover(): int {
        $count=0;
        foreach(config('news.sources',[]) as $source){
            $response=Http::timeout(20)->withHeaders(['User-Agent'=>'MatchdayAfrica/1.0'])->get($source['url']);
            if(!$response->successful()){Log::warning('Football news feed request failed',['source'=>$source['name'],'status'=>$response->status()]);continue;}
            $xml=@simplexml_load_string($response->body(),'SimpleXMLElement',LIBXML_NOCDATA);
            if(!$xml){Log::warning('Football news feed XML was invalid',['source'=>$source['name']]);continue;}
            foreach($xml->channel->item??[] as $item){
                $title=trim((string)$item->title);$url=trim((string)$item->link);if(!$title||!filter_var($url,FILTER_VALIDATE_URL))continue;
                $summary=Str::limit(trim(strip_tags((string)$item->description)),1200,'');
                $published=($date=trim((string)$item->pubDate))?Carbon::parse($date):now();
                $fingerprint=hash('sha256',Str::lower(preg_replace('/[^a-z0-9]+/i',' ',$title)));
                $haystack=Str::lower($title.' '.$summary);$africa=collect(config('news.africa_keywords',[]))->contains(fn($word)=>str_contains($haystack,$word));
                NewsCandidate::firstOrCreate(['fingerprint'=>$fingerprint],['source'=>$source['name'],'source_guid'=>(string)$item->guid,'source_url'=>$url,'title'=>$title,'summary'=>$summary,'source_published_at'=>$published,'selection_score'=>(int)$source['priority']+($africa?100:0)]);
                $count++;
            }
        }
        return $count;
    }

    private function turnIntoPost(NewsCandidate $candidate): Blog {
        $prompt="You are the copy editor for Matchday Africa. Write an original, accurate football news brief using ONLY the supplied facts. Do not invent quotes, statistics, context, player details or conclusions. If the facts are insufficient, return REJECT. Use a confident African football publication voice. Return exactly: TITLE: one line; EXCERPT: one line under 180 characters; BODY: 3 to 5 HTML <p> paragraphs, 220-350 words. Paraphrase; do not copy phrases from the source. End the BODY with: <p><a href=\"{$candidate->source_url}\" rel=\"nofollow noopener\" target=\"_blank\">Read the original report at {$candidate->source}</a></p>\n\nSOURCE: {$candidate->source}\nHEADLINE: {$candidate->title}\nPUBLISHED: {$candidate->source_published_at?->toIso8601String()}\nFACT SUMMARY: {$candidate->summary}";
        $edited=$this->editor->editFootballArticle($prompt);
        if(!$edited||str_contains(Str::upper($edited),'REJECT')) throw new \RuntimeException('Editorial model rejected insufficient facts.');
        if(!preg_match('/TITLE:\s*(.+)\R+EXCERPT:\s*(.+)\R+BODY:\s*(.+)/s',$edited,$m)) throw new \RuntimeException('Editorial response failed structure validation.');
        $title=trim(strip_tags($m[1]));$excerpt=trim(strip_tags($m[2]));$body=trim($m[3]);
        if(Str::length($title)<12||Str::length($title)>180||Str::length($excerpt)>200||substr_count($body,'<p>')<3||!str_contains($body,$candidate->source_url)) throw new \RuntimeException('Article failed publication validation.');
        $blog=Blog::create(['title'=>$title,'slug'=>Str::slug($title).'-'.$candidate->id,'excerpt'=>$excerpt,'content'=>$body,'status'=>'published','published_at'=>now(),'author_name'=>'Matchday Africa News Desk','metadata'=>['category'=>'Football News','automated'=>true,'source'=>$candidate->source,'source_url'=>$candidate->source_url,'source_published_at'=>$candidate->source_published_at?->toIso8601String(),'editor_model'=>config('services.openrouter.model')]]);
        $candidate->update(['status'=>'published','blog_id'=>$blog->id]);
        return $blog;
    }
}
