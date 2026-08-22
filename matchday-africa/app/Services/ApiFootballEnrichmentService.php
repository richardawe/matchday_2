<?php

namespace App\Services;

use App\Http\Controllers\DiscoveryController;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\Player;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ApiFootballEnrichmentService
{
    private int $calls = 0;
    private ?string $remaining = null;

    public function syncDate(string $date): array
    {
        if ((string) config('services.api_football.key') === '') return ['configured'=>false,'fixtures'=>0,'eligible'=>0,'matched'=>0,'events'=>0,'calls'=>0];
        $matches = FootballMatch::with(['homeTeam','awayTeam','league'])->whereDate('match_date',$date)->get();
        $eligible = $this->eligible($matches);
        if ($eligible->isEmpty()) return $this->result(0,0,0,0);

        $fixtures = $this->fetch('fixtures',['date'=>$date,'timezone'=>config('app.timezone','UTC')],10800);
        $matched=0; $saved=0;
        foreach ($fixtures as $fixture) {
            $match=$this->matchLocal($eligible,$fixture);
            if (!$match) continue;
            $matched++; $id=(int)data_get($fixture,'fixture.id'); $this->link($match,$id);
            if (!$match->match_date?->between(now()->subHours(6),now()->addMinutes(90))) continue;

            $this->storeLineups($match,$id,$this->fetch('fixtures/lineups',['fixture'=>$id],43200,1200));
            if ($this->live($match)||$this->finished($match)) {
                $ttl=$this->live($match)?4200:43200;
                $events=$this->fetch('fixtures/events',['fixture'=>$id],$ttl,1200);
                $stats=$this->fetch('fixtures/statistics',['fixture'=>$id],$ttl,1200);
                $saved+=$this->storeMatch($match,$fixture,$events,$stats);
                $this->storePlayers($match,$id,$this->fetch('fixtures/players',['fixture'=>$id],$this->live($match)?7200:43200,1800));
            }
        }
        return $this->result(count($fixtures),$eligible->count(),$matched,$saved);
    }

    private function eligible(Collection $matches): Collection
    {
        $teamIds=$matches->flatMap(fn($m)=>[$m->home_team_id,$m->away_team_id])->filter()->unique();
        $african=Player::active()->whereIn('team_id',$teamIds)->whereIn('nationality_code',DiscoveryController::AFRICA)->pluck('team_id')->flip();
        return $matches->filter(fn($m)=>$this->epl($m)||$african->has($m->home_team_id)||$african->has($m->away_team_id))
            ->sortByDesc(fn($m)=>($this->live($m)?100:0)+($this->epl($m)?10:0))->values();
    }

    private function fetch(string $endpoint,array $query,int $ttl,int $emptyTtl=600): array
    {
        $key='api-football:'.sha1($endpoint.'|'.json_encode($query));
        $cached=Cache::get($key); if(is_array($cached)) return $cached;
        $used=(int)Cache::get($this->usageKey(),0);
        if($used>=(int)config('services.api_football.daily_budget',95)||($this->remaining!==null&&(int)$this->remaining<=1)) return [];
        $response=Http::timeout(35)->withHeaders(['x-apisports-key'=>config('services.api_football.key')])
            ->get(rtrim(config('services.api_football.url'),'/').'/'.$endpoint,$query);
        $this->record($response);
        if(!$response->successful()||!empty($response->json('errors'))) throw new \RuntimeException('API-Football '.$endpoint.': '.json_encode($response->json('errors')?:$response->body()));
        $data=$response->json('response',[]); $data=is_array($data)?$data:[];
        Cache::put($key,$data,now()->addSeconds(empty($data)?$emptyTtl:$ttl));
        return $data;
    }

    private function record(Response $response): void
    {
        $this->calls++; Cache::put($this->usageKey(),(int)Cache::get($this->usageKey(),0)+1,now()->endOfDay());
        $this->remaining=$response->header('x-ratelimit-requests-remaining')?:$this->remaining;
    }
    private function usageKey(): string { return 'api-football:daily-calls:'.now()->toDateString(); }
    private function live(FootballMatch $m): bool { return in_array(strtoupper((string)$m->status),FootballMatch::LIVE_STATUSES,true); }
    private function finished(FootballMatch $m): bool { return in_array(strtoupper((string)$m->status),['FINISHED','AWARDED','FT','AET','PEN'],true); }
    private function epl(FootballMatch $m): bool
    {
        return strtoupper((string)$m->league?->short_code)==='PL'||(strtolower((string)$m->league?->name)==='premier league'&&in_array(strtoupper((string)$m->league?->country_code),['GB','GBR','ENG'],true));
    }

    private function link(FootballMatch $match,int $id): void
    {
        $meta=$this->meta($match->metadata); $meta['api_football_fixture_id']=$id; $meta['event_provider']='api-football';
        $match->update(['metadata'=>$meta]);
    }

    private function storeLineups(FootballMatch $match,int $id,array $sides): void
    {
        $players=$this->africanPlayers($match);
        foreach($sides as $side){$team=$this->localTeam($match,(string)data_get($side,'team.name'));if(!$team)continue;
            $starters=collect(data_get($side,'startXI',[]))->pluck('player.id');
            foreach(array_merge(data_get($side,'startXI',[]),data_get($side,'substitutes',[])) as $row){$p=data_get($row,'player',[]);$player=$this->findPlayer($players,$team->id,(string)data_get($p,'name'));if(!$player)continue;
                $meta=$this->meta($player->metadata);$meta['api_football_player_id']=data_get($p,'id');$meta['api_football_lineup']=[
                    'date'=>$match->match_date?->toDateString(),'match_id'=>$match->id,'fixture_id'=>$id,'starter'=>$starters->contains(data_get($p,'id')),
                    'formation'=>data_get($side,'formation'),'number'=>data_get($p,'number'),'position'=>data_get($p,'pos'),'grid'=>data_get($p,'grid')];
                $player->update(['metadata'=>$meta,'last_api_update'=>now()]);
            }
        }
    }

    private function storePlayers(FootballMatch $match,int $id,array $sides): void
    {
        $players=$this->africanPlayers($match);
        foreach($sides as $side){$team=$this->localTeam($match,(string)data_get($side,'team.name'));if(!$team)continue;
            foreach(data_get($side,'players',[]) as $row){$player=$this->findPlayer($players,$team->id,(string)data_get($row,'player.name'));if(!$player)continue;
                $meta=$this->meta($player->metadata);$meta['api_football_player_id']=data_get($row,'player.id');$meta['api_football_stats']=[
                    'date'=>$match->match_date?->toDateString(),'match_id'=>$match->id,'fixture_id'=>$id,'statistics'=>data_get($row,'statistics.0',[])];
                $player->update(['photo_url'=>data_get($row,'player.photo')?:$player->photo_url,'metadata'=>$meta,'last_api_update'=>now()]);
            }
        }
    }

    private function africanPlayers(FootballMatch $match): Collection
    {
        return Player::active()->whereIn('team_id',[$match->home_team_id,$match->away_team_id])->whereIn('nationality_code',DiscoveryController::AFRICA)->get();
    }
    private function findPlayer(Collection $players,int $teamId,string $name): ?Player
    {
        $needle=$this->normalize($name);return $players->where('team_id',$teamId)->first(fn($p)=>$this->normalize($p->name)===$needle||($needle!==''&&(str_contains($this->normalize($p->name),$needle)||str_contains($needle,$this->normalize($p->name)))));
    }
    private function localTeam(FootballMatch $m,string $name){return $this->normalize($name)===$this->normalize($m->homeTeam?->name??'')?$m->homeTeam:$m->awayTeam;}
    private function matchLocal(Collection $matches,array $fixture): ?FootballMatch
    {
        $home=$this->normalize((string)data_get($fixture,'teams.home.name',''));$away=$this->normalize((string)data_get($fixture,'teams.away.name',''));if($home===''||$away==='')return null;
        return $matches->first(function($m)use($home,$away){$lh=$this->normalize($m->homeTeam?->name??'');$la=$this->normalize($m->awayTeam?->name??'');return($lh===$home||str_contains($lh,$home)||str_contains($home,$lh))&&($la===$away||str_contains($la,$away)||str_contains($away,$la));});
    }

    private function storeMatch(FootballMatch $match,array $fixture,array $events,array $statistics): int
    {
        $id=(int)data_get($fixture,'fixture.id');$updates=[];
        foreach($statistics as $side){$prefix=(int)data_get($side,'team.id')===(int)data_get($fixture,'teams.home.id')?'home':'away';foreach(data_get($side,'statistics',[])as$stat){$column=match(data_get($stat,'type')){'Ball Possession'=>'possession','Total Shots'=>'shots','Shots on Goal'=>'shots_on_target','Corner Kicks'=>'corners','Fouls'=>'fouls','Yellow Cards'=>'yellow_cards','Red Cards'=>'red_cards',default=>null};if($column)$updates[$prefix.'_'.$column]=$this->number(data_get($stat,'value'));}}
        if($updates)$match->update($updates);
        foreach($events as $index=>$event){$type=match(data_get($event,'type')){'Goal'=>'goal','Card'=>str_contains((string)data_get($event,'detail'),'Red')?'red_card':'yellow_card','subst'=>'substitution',default=>Str::snake((string)data_get($event,'type','event'))};$team=(int)data_get($event,'team.id')===(int)data_get($fixture,'teams.home.id')?$match->homeTeam:$match->awayTeam;if(!$team)continue;
            $eventKey=$id.'|'.$index.'|'.data_get($event,'time.elapsed').'|'.data_get($event,'player.id').'|'.$type;
            MatchEvent::updateOrCreate(['football_data_id'=>$this->stableId($eventKey)],[
                'match_id'=>$match->id,'match_football_data_id'=>$match->football_data_id,'team_id'=>$team->id,'team_football_data_id'=>$team->football_data_id,
                'type'=>$type,'sub_type'=>Str::snake((string)data_get($event,'detail','')),'minute'=>(int)data_get($event,'time.elapsed',0),'extra_minute'=>data_get($event,'time.extra'),
                'player_name'=>data_get($event,'player.name'),'assist_player_name'=>data_get($event,'assist.name'),'related_player_name'=>$type==='substitution'?data_get($event,'assist.name'):null,
                'reason'=>in_array($type,['yellow_card','red_card'],true)?data_get($event,'comments'):null,'description'=>data_get($event,'detail'),
                'is_own_goal'=>data_get($event,'detail')==='Own Goal','is_penalty'=>str_contains((string)data_get($event,'detail'),'Penalty'),'sort_order'=>$index,
                'metadata'=>['provider'=>'api-football','provider_fixture_id'=>$id]]);
        }return count($events);
    }

    private function result(int $fixtures,int $eligible,int $matched,int $events): array
    {return ['configured'=>true,'fixtures'=>$fixtures,'eligible'=>$eligible,'matched'=>$matched,'events'=>$events,'calls'=>$this->calls,'daily_used'=>(int)Cache::get($this->usageKey(),0),'remaining'=>$this->remaining];}
    private function normalize(string $v):string{return trim(preg_replace('/[^a-z0-9]+/',' ',Str::lower(Str::ascii($v))));}
    private function meta(mixed $v):array{if(is_array($v))return$v;if(!is_string($v)||trim($v)==='')return[];$d=json_decode($v,true);if(is_string($d))$d=json_decode($d,true);return is_array($d)?$d:[];}
    private function number($v):?int{return$v===null?null:(int)preg_replace('/[^0-9-]/','',(string)$v);}
    private function stableId(string $v):int{return(int)hexdec(substr(hash('sha256','api-football|'.$v),0,15));}
}
