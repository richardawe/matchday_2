<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\MatchEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Player;

class ApiFootballEnrichmentService
{
    public function syncDate(string $date): array
    {
        $key=(string)config('services.api_football.key');
        if($key==='')return ['configured'=>false,'fixtures'=>0,'matched'=>0,'events'=>0];
        $local=FootballMatch::with(['homeTeam','awayTeam'])->whereDate('match_date',$date)->get();
        if($local->isEmpty())return ['configured'=>true,'fixtures'=>0,'matched'=>0,'events'=>0];
        $response=Http::timeout(35)->withHeaders(['x-apisports-key'=>$key])->get(rtrim(config('services.api_football.url'),'/').'/fixtures',['date'=>$date,'timezone'=>config('app.timezone','UTC')]);
        if(!$response->successful()||!empty($response->json('errors')))throw new \RuntimeException('API-Football: '.json_encode($response->json('errors')?:$response->body()));
        $matched=0;$events=0;
        foreach($response->json('response',[]) as $fixture){
            $match=$this->matchLocal($local,$fixture); if(!$match)continue;
            $matched++;$events+=$this->store($match,$fixture);
            $this->storePlayerStats($match,(int)data_get($fixture,'fixture.id'));
        }
        return ['configured'=>true,'fixtures'=>count($response->json('response',[])),'matched'=>$matched,'events'=>$events,'remaining'=>$response->header('x-ratelimit-requests-remaining')];
    }

    private function storePlayerStats(FootballMatch $match,int $fixtureId): void
    {
        if($fixtureId<=0)return;
        $teamIds=[$match->home_team_id,$match->away_team_id];
        $players=Player::whereIn('team_id',$teamIds)->active()->get();
        if($players->isEmpty())return;
        $response=Http::timeout(35)->withHeaders(['x-apisports-key'=>config('services.api_football.key')])
            ->get(rtrim(config('services.api_football.url'),'/').'/fixtures/players',['fixture'=>$fixtureId]);
        if(!$response->successful()||!empty($response->json('errors')))return;
        foreach($response->json('response',[]) as $side){
            $providerTeam=$this->normalize((string)data_get($side,'team.name'));
            $localTeam=$providerTeam===$this->normalize($match->homeTeam?->name??'')?$match->homeTeam:$match->awayTeam;
            if(!$localTeam)continue;
            foreach(data_get($side,'players',[]) as $row){
                $name=(string)data_get($row,'player.name');
                $player=$players->where('team_id',$localTeam->id)->first(fn($candidate)=>
                    $this->normalize($candidate->name)===$this->normalize($name)
                    || str_contains($this->normalize($candidate->name),$this->normalize($name))
                );
                if(!$player)continue;
                $metadata=$this->metadataArray($player->metadata);
                $metadata['api_football_player_id']=data_get($row,'player.id');
                $metadata['api_football_stats']=[
                    'date'=>$match->match_date?->toDateString(),'match_id'=>$match->id,
                    'fixture_id'=>$fixtureId,'statistics'=>data_get($row,'statistics.0',[]),
                ];
                $player->update(['photo_url'=>data_get($row,'player.photo')?:$player->photo_url,'metadata'=>$metadata,'last_api_update'=>now()]);
            }
        }
    }

    private function matchLocal($matches,array $fixture): ?FootballMatch
    {
        $home=$this->normalize(data_get($fixture,'teams.home.name',''));
        $away=$this->normalize(data_get($fixture,'teams.away.name',''));
        if($home===''||$away==='')return null;
        return $matches->first(function($m)use($home,$away){
            $lh=$this->normalize($m->homeTeam?->name??'');$la=$this->normalize($m->awayTeam?->name??'');
            return ($lh===$home||str_contains($lh,$home)||str_contains($home,$lh))&&($la===$away||str_contains($la,$away)||str_contains($away,$la));
        });
    }

    private function store(FootballMatch $match,array $fixture): int
    {
        $providerId=(int)data_get($fixture,'fixture.id');
        $metadata=$this->metadataArray($match->metadata);$metadata['api_football_fixture_id']=$providerId;$metadata['event_provider']='api-football';
        $updates=['metadata'=>$metadata];
        foreach(data_get($fixture,'statistics',[]) as $side){
            $prefix=(int)data_get($side,'team.id')===(int)data_get($fixture,'teams.home.id')?'home':'away';
            foreach(data_get($side,'statistics',[]) as $stat){
                $column=match(data_get($stat,'type')){'Ball Possession'=>'possession','Total Shots'=>'shots','Shots on Goal'=>'shots_on_target','Corner Kicks'=>'corners','Fouls'=>'fouls','Yellow Cards'=>'yellow_cards','Red Cards'=>'red_cards',default=>null};
                if($column)$updates[$prefix.'_'.$column]=$this->number(data_get($stat,'value'));
            }
        }
        $match->update($updates);
        $count=0;
        foreach(data_get($fixture,'events',[]) as $index=>$event){
            $type=match(data_get($event,'type')){'Goal'=>'goal','Card'=>str_contains((string)data_get($event,'detail'),'Red')?'red_card':'yellow_card','subst'=>'substitution',default=>Str::snake((string)data_get($event,'type','event'))};
            $team=(int)data_get($event,'team.id')===(int)data_get($fixture,'teams.home.id')?$match->homeTeam:$match->awayTeam;
            if(!$team)continue;
            $eventKey=$providerId.'|'.$index.'|'.data_get($event,'time.elapsed').'|'.data_get($event,'player.id').'|'.$type;
            MatchEvent::updateOrCreate(['football_data_id'=>$this->stableId($eventKey)],[
                'match_id'=>$match->id,'match_football_data_id'=>$match->football_data_id,'team_id'=>$team->id,'team_football_data_id'=>$team->football_data_id,
                'type'=>$type,'sub_type'=>Str::snake((string)data_get($event,'detail','')),'minute'=>(int)data_get($event,'time.elapsed',0),'extra_minute'=>data_get($event,'time.extra'),
                'player_name'=>data_get($event,'player.name'),'assist_player_name'=>data_get($event,'assist.name'),'related_player_name'=>$type==='substitution'?data_get($event,'assist.name'):null,
                'reason'=>$type==='yellow_card'||$type==='red_card'?data_get($event,'comments'):null,'description'=>data_get($event,'detail'),
                'is_own_goal'=>data_get($event,'detail')==='Own Goal','is_penalty'=>str_contains((string)data_get($event,'detail'),'Penalty'),'sort_order'=>$index,
                'metadata'=>['provider'=>'api-football','provider_fixture_id'=>$providerId],
            ]);$count++;
        }
        return $count;
    }

    private function normalize(string $value): string{return trim(preg_replace('/[^a-z0-9]+/',' ',Str::lower(Str::ascii($value))));}
    private function metadataArray(mixed $value): array
    {
        if(is_array($value))return $value;
        if(!is_string($value)||trim($value)==='')return [];
        $decoded=json_decode($value,true);
        if(is_string($decoded))$decoded=json_decode($decoded,true);
        return is_array($decoded)?$decoded:[];
    }
    private function number($value): ?int{return $value===null?null:(int)preg_replace('/[^0-9-]/','',(string)$value);}
    private function stableId(string $value): int{return (int)hexdec(substr(hash('sha256','api-football|'.$value),0,15));}
}
