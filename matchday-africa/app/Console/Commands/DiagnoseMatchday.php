<?php
namespace App\Console\Commands;
use App\Models\NewsCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiagnoseMatchday extends Command {
    protected $signature='matchday:diagnose';
    protected $description='Check football API, news feeds and OpenRouter without displaying secrets';
    public function handle():int{
        $footballKey=(string)config('services.football_data.key');
        $openRouterKey=(string)config('services.openrouter.api_key');
        $checks=[['Football API key',$footballKey?'Configured':'MISSING',''],['OpenRouter key',$openRouterKey?'Configured':'MISSING','']];
        try{$r=Http::timeout(15)->withHeaders(['X-Auth-Token'=>$footballKey])->get(rtrim(config('services.football_data.url'),'/').'/matches',['dateFrom'=>now()->toDateString(),'dateTo'=>now()->toDateString()]);$checks[]=['Football API HTTP',$r->status(),$r->successful()?'Reachable':$this->safeError($r)];}catch(\Throwable $e){$checks[]=['Football API HTTP','ERROR',$e->getMessage()];}
        foreach(config('news.sources',[]) as $source){try{$r=Http::timeout(15)->withHeaders(['User-Agent'=>'MatchdayAfrica/1.0'])->get($source['url']);$checks[]=['Feed: '.$source['name'],$r->status(),$r->successful()?'Reachable':$this->safeError($r)];}catch(\Throwable $e){$checks[]=['Feed: '.$source['name'],'ERROR',$e->getMessage()];}}
        try{$r=Http::timeout(15)->withToken($openRouterKey)->get(rtrim(config('services.openrouter.base_url'),'/').'/models');$checks[]=['OpenRouter HTTP',$r->status(),$r->successful()?'Reachable':$this->safeError($r)];}catch(\Throwable $e){$checks[]=['OpenRouter HTTP','ERROR',$e->getMessage()];}
        try{
            $r=Http::timeout(45)->withHeaders(['Authorization'=>'Bearer '.$openRouterKey,'Content-Type'=>'application/json','HTTP-Referer'=>config('app.url'),'X-Title'=>'Matchday Africa Diagnostics'])->post(rtrim(config('services.openrouter.base_url'),'/').'/chat/completions',[
                'model'=>config('services.openrouter.model'),
                'messages'=>[['role'=>'user','content'=>'Reply with exactly MATCHDAY_OK and nothing else.']],
                'max_tokens'=>128,'temperature'=>0,
                'reasoning'=>['effort'=>'none','exclude'=>true],
            ]);
            $reply=trim((string)($r->json('choices.0.message.content')??''));
            $routedModel=(string)($r->json('model')??'unknown');
            $finish=(string)($r->json('choices.0.finish_reason')??'unknown');
            $unexpected='Empty reply; routed='.$routedModel.'; finish='.$finish.'; completion_tokens='.(string)($r->json('usage.completion_tokens')??'unknown');
            $checks[]=['OpenRouter completion',$r->status(),$r->successful()?($reply==='MATCHDAY_OK'?'Model replied correctly via '.$routedModel:($reply!==''?'Unexpected reply: '.substr($reply,0,80):$unexpected)):$this->safeError($r)];
            $checks[]=['OpenRouter model',config('services.openrouter.model'),'Primary'];
            $checks[]=['OpenRouter fallbacks',implode(', ',config('services.openrouter.fallback_models', [])),'Automatic'];
        }catch(\Throwable $e){$checks[]=['OpenRouter completion','ERROR',$e->getMessage()];}
        $checks[]=['News candidates',NewsCandidate::count(),NewsCandidate::where('status','failed')->count().' failed'];
        foreach(NewsCandidate::where('status','failed')->latest('updated_at')->limit(3)->get() as $candidate){$checks[]=['Rejected: '.substr($candidate->title,0,34),'FAILED',substr((string)$candidate->failure_reason,0,180)];}
        $this->table(['Check','Result','Detail'],$checks);return self::SUCCESS;
    }
    private function safeError($response):string{$json=$response->json();return substr((string)($json['message']??$json['error']['message']??$json['error']??'Request rejected'),0,180);}
}
