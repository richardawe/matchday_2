<?php
namespace App\Http\Controllers;
use App\Models\{AnalyticsEvent,FootballMatch,NotificationPreference,Team,UserFavorite,UserPrediction};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExperienceController extends Controller {
    public function onboarding(){
        $selected=auth()->user()->favorites()->where('favorable_type',Team::class)->pluck('favorable_id');
        return view('experience.onboarding',['teams'=>Team::active()->orderBy('name')->get(),'selected'=>$selected]);
    }
    public function saveTeams(Request $request){
        $ids=$request->validate(['teams'=>'required|array|min:1|max:8','teams.*'=>'exists:teams,id'])['teams'];
        auth()->user()->favorites()->where('favorable_type',Team::class)->delete();
        foreach($ids as $id) UserFavorite::create(['user_id'=>auth()->id(),'favorable_type'=>Team::class,'favorable_id'=>$id]);
        return redirect()->route('home')->with('success','Your watchtower is ready.');
    }
    public function toggleTeam(Team $team){
        $fav=UserFavorite::where(['user_id'=>auth()->id(),'favorable_type'=>Team::class,'favorable_id'=>$team->id])->first();
        $fav ? $fav->delete() : UserFavorite::create(['user_id'=>auth()->id(),'favorable_type'=>Team::class,'favorable_id'=>$team->id]);
        return back();
    }
    public function settings(){
        $preferences=NotificationPreference::firstOrCreate(['user_id'=>auth()->id()]); return view('experience.settings',compact('preferences'));
    }
    public function saveSettings(Request $request){
        NotificationPreference::updateOrCreate(['user_id'=>auth()->id()],[
            'match_alerts'=>$request->boolean('match_alerts'),'prediction_reminders'=>$request->boolean('prediction_reminders'),
            'weekly_digest'=>$request->boolean('weekly_digest'),'digest_day'=>$request->validate(['digest_day'=>'required|in:monday,friday,sunday'])['digest_day']]);
        return back()->with('success','Notification settings saved.');
    }
    public function track(Request $request){
        $data=$request->validate(['event'=>'required|string|max:80','path'=>'nullable|string|max:500','properties'=>'nullable|array']);
        AnalyticsEvent::create($data+['user_id'=>auth()->id(),'visitor_id'=>$request->cookie('md_visitor')]); return response()->noContent();
    }
}
