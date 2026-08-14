<?php
namespace App\Http\Controllers\War;
use App\Http\Controllers\Controller;
use App\Models\WarRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarRoomController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data=$request->validate(['playerId'=>'required|uuid','team'=>'required|integer|min:0|max:30']);
        do{$code=strtoupper(Str::random(6));}while(WarRoom::find($code));
        WarRoom::create(['code'=>$code,'host_token'=>$data['playerId'],'host_user_id'=>$request->user()?->id,'host_team'=>$data['team'],'state'=>$this->initialState(),'expires_at'=>now()->addHours(2)]);
        return response()->json(['code'=>$code,'side'=>0]);
    }

    public function show(WarRoom $room): JsonResponse
    {
        abort_if($room->expires_at->isPast(),410,'Room expired');
        $state=$this->timedState($room);
        return response()->json(['code'=>$room->code,'hostTeam'=>$room->host_team,'guestTeam'=>$room->guest_team,'connected'=>(bool)$room->guest_token,'state'=>$state]);
    }

    public function join(Request $request,WarRoom $room): JsonResponse
    {
        $data=$request->validate(['playerId'=>'required|uuid','team'=>'required|integer|min:0|max:30']);
        abort_if($room->guest_token&&$room->guest_token!==$data['playerId'],409,'Room is full');
        $state=$room->state;$state['status']='ready';$state['event']='Both commanders are ready';
        $room->update(['guest_token'=>$data['playerId'],'guest_user_id'=>$request->user()?->id,'guest_team'=>$data['team'],'state'=>$state]);
        return response()->json(['code'=>$room->code,'side'=>1]);
    }

    public function start(Request $request,WarRoom $room): JsonResponse
    {
        $data=$request->validate(['playerId'=>'required|uuid']);abort_unless($room->host_token===$data['playerId'],403,'Host only');abort_unless($room->guest_token,409,'Waiting for player two');
        $state=$room->state;$state['status']='playing';$state['endsAt']=now()->addSeconds(60)->getTimestampMs();$state['remaining']=60;$state['event']='Kick off! The home banner advances.';$room->update(['state'=>$state]);return response()->json($state);
    }

    public function action(Request $request,WarRoom $room): JsonResponse
    {
        $data=$request->validate(['playerId'=>'required|uuid','move'=>'required|in:attack,defend,counter,shoot']);
        return DB::transaction(function()use($data,$room){$room=WarRoom::whereKey($room->code)->lockForUpdate()->firstOrFail();$side=$room->host_token===$data['playerId']?0:($room->guest_token===$data['playerId']?1:-1);$state=$this->timedState($room);abort_if($side<0,403,'Player is not in this room');abort_unless($state['status']==='playing'&&$state['turn']===$side,409,'Not your turn');$dir=$side===0?1:-1;$move=$data['move'];
            if($move==='attack'&&$state['possession']===$side){$state['ball']=max(8,min(92,$state['ball']+14*$dir));$state['event']='The attack gains ground';}
            elseif(in_array($move,['defend','counter'])&&$state['possession']!==$side){$won=random_int(1,100)<=($move==='counter'?58:72);if($won){$state['possession']=$side;$state['ball']=max(8,min(92,$state['ball']+($move==='counter'?10:2)*$dir));$state['event']='The banner is reclaimed';}else{$state['event']='The shield wall holds';}}
            elseif($move==='shoot'&&$state['possession']===$side&&($side===0?$state['ball']>=76:$state['ball']<=24)){if(random_int(1,100)<=50){$state['score'][$side]++;$state['event']='Goal! The raid lands and the gate is breached';}else{$state['event']='The fortress holds';}$state['ball']=50;$state['possession']=$side===0?1:0;}
            else abort(409,$state['possession']===$side?'Attack or move into shooting range':'Win the ball with tackle or counter');
            $state['turn']=$side===0?1:0;$room->update(['state'=>$state]);return response()->json($state);});
    }

    private function initialState():array{return ['status'=>'waiting','ball'=>50,'possession'=>0,'score'=>[0,0],'turn'=>0,'remaining'=>60,'endsAt'=>0,'event'=>'Waiting for an opponent'];}
    private function timedState(WarRoom $room):array{$state=$room->state;if($state['status']==='playing'&&$state['endsAt']){$state['remaining']=max(0,(int)ceil(($state['endsAt']-now()->getTimestampMs())/1000));if(!$state['remaining']){$state['status']='finished';$room->update(['state'=>$state]);}}return $state;}
}
