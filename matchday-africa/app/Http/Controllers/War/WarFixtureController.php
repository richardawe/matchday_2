<?php
namespace App\Http\Controllers\War;
use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use Illuminate\Http\JsonResponse;

class WarFixtureController extends Controller
{
    public function index(): JsonResponse
    {
        $matches=FootballMatch::with(['homeTeam:id,name,common_name','awayTeam:id,name,common_name','league:id,name'])
            ->whereBetween('match_date',[now()->subDay(),now()->addDays(14)])
            ->orderBy('match_date')->get();
        return response()->json($matches->map(fn($match)=>$this->shape($match)));
    }

    public function show(FootballMatch $match): JsonResponse
    {
        $match->load(['homeTeam','awayTeam','league','events']);
        return response()->json($this->shape($match)+['events'=>$match->events]);
    }

    private function shape(FootballMatch $match): array
    {
        $form=function($teamId){return FootballMatch::where(fn($q)=>$q->where('home_team_id',$teamId)->orWhere('away_team_id',$teamId))->where('status','FINISHED')->latest('match_date')->take(5)->get()->map(function($m)use($teamId){$for=$m->home_team_id===$teamId?$m->home_score:$m->away_score;$against=$m->home_team_id===$teamId?$m->away_score:$m->home_score;return $for>$against?'W':($for===$against?'D':'L');})->values();};
        return ['id'=>$match->id,'externalId'=>$match->football_data_id,'kickoff'=>$match->match_date?->toIso8601String(),'status'=>$match->status,'minute'=>$match->minute,'home'=>$match->homeTeam?->display_name,'away'=>$match->awayTeam?->display_name,'homeScore'=>$match->home_score,'awayScore'=>$match->away_score,'league'=>$match->league?->name,'featured'=>$match->is_featured,'homeForm'=>$form($match->home_team_id),'awayForm'=>$form($match->away_team_id),'homeStrength'=>$this->strength($form($match->home_team_id)),'awayStrength'=>$this->strength($form($match->away_team_id))];
    }
    private function strength($form):int{return max(45,min(85,55+$form->sum(fn($r)=>$r==='W'?5:($r==='D'?1:-3))));}
}
