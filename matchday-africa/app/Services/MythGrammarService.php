<?php
namespace App\Services;use App\Models\FootballMatch;
class MythGrammarService {
 public function tell(FootballMatch $match):array{$events=$match->events()->orderBy('minute')->orderBy('sort_order')->get();$beats=[];
 foreach($events as $event){$team=$event->team_id===$match->home_team_id?$match->homeTeam?->name:$match->awayTeam?->name;$player=$event->player_name?:'A warrior';$text=match($event->type){
  'goal','penalty_goal'=>($event->is_penalty?'A siege tower breaches the gate':'A raid lands')." — {$player} strikes for {$team}.",
  'yellow_card'=>"{$player} is struck down, wounded, and rises again.",'red_card'=>"{$player} is captured and dragged from the field in chains.",
  default=>null};if($text)$beats[]=['minute'=>$event->minute,'event'=>$event->type,'text'=>$text];}
 $home=$match->homeTeam?->name??'The home banner';$away=$match->awayTeam?->name??'The visitors';$hs=$match->home_score??0;$as=$match->away_score??0;
 if($match->isFinished()){if($hs===$as){$headline='Both banners remain standing';$ending='At dusk, neither banner fell. The field was shared.';}else{$winner=$hs>$as?$home:$away;$headline=$winner.' claims the field';$ending="The war-horns sounded for {$winner}, whose line held when the final light faded.";}}else{$headline=$home.' and '.$away.' gather at the gates';$ending='The field awaits its first breach.';}
 return ['headline'=>$headline,'story'=>implode(' ',array_column($beats,'text')).' '.$ending,'beats'=>$beats];}
}
