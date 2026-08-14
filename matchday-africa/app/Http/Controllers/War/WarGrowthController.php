<?php
namespace App\Http\Controllers\War;
use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Models\WarCampaign;
use App\Models\WarReferral;
use App\Models\WarSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WarGrowthController extends Controller
{
    public function subscribe(Request $request):JsonResponse{$data=$request->validate(['email'=>'required|email|max:255']);$subscriber=WarSubscriber::updateOrCreate(['email'=>strtolower($data['email'])],['consented_at'=>now(),'unsubscribed_at'=>null]);return response()->json(['ok'=>true,'id'=>$subscriber->id]);}
    public function referral(Request $request):JsonResponse{$data=$request->validate(['id'=>'nullable|uuid','fixtureId'=>'nullable|integer|exists:matches,id','source'=>'required|string|max:40','campaign'=>'nullable|string|max:255','event'=>'required|in:landed,challenge,joined,completed']);$id=$data['id']??(string)Str::uuid();$ref=WarReferral::firstOrCreate(['id'=>$id],['match_id'=>$data['fixtureId']??null,'source'=>$data['source'],'campaign'=>$data['campaign']??null]);$ref->{$data['event'].'_at'}=now();$ref->save();return response()->json(['id'=>$ref->id]);}
    public function campaigns():JsonResponse{$this->authorizeAdmin();return response()->json(WarCampaign::with('match.homeTeam','match.awayTeam')->latest()->get()->map(fn($c)=>['id'=>$c->id,'home'=>$c->match?->homeTeam?->display_name,'away'=>$c->match?->awayTeam?->display_name,'channel'=>$c->channel,'kind'=>$c->kind,'caption'=>$c->caption,'status'=>$c->status,'kickoff'=>$c->match?->match_date?->toIso8601String()]));}
    public function generate():JsonResponse{$this->authorizeAdmin();$matches=FootballMatch::with(['homeTeam','awayTeam'])->whereBetween('match_date',[now(),now()->addDays(10)])->orderByDesc('is_featured')->orderBy('match_date')->take(3)->get();foreach($matches as $match)foreach(['x','instagram','tiktok'] as $channel)foreach(['pre','post'] as $kind)WarCampaign::updateOrCreate(['match_id'=>$match->id,'channel'=>$channel,'kind'=>$kind],['caption'=>$this->caption($match,$kind),'status'=>'draft']);return response()->json(['ok'=>true,'hero'=>$matches->pluck('id'),'drafts'=>$matches->count()*6]);}
    public function update(Request $request,WarCampaign $campaign):JsonResponse{$this->authorizeAdmin();$data=$request->validate(['action'=>'required|in:approve,reject']);$campaign->update(['status'=>$data['action']==='approve'?'approved':'rejected','approved_by'=>$request->user()->id,'approved_at'=>now()]);return response()->json(['ok'=>true]);}
    private function caption(FootballMatch $match,string $kind):string{$home=$match->homeTeam?->display_name??'Home';$away=$match->awayTeam?->display_name??'Away';if($kind==='pre')return "{$home} face {$away}. Two banners enter; one battlefield decides it. Choose your army at matchday.africa/war.";if($match->home_score===$match->away_score)return "{$home} and {$away} plant both banners at dusk. Neither falls; the field is shared.";$winner=$match->home_score>$match->away_score?$home:$away;$loser=$winner===$home?$away:$home;return "{$winner}'s raid lands. The line is breached and {$loser}'s banner falls.";}
    private function authorizeAdmin():void{abort_unless(auth()->check()&&auth()->user()->isAdmin(),403);}
}
