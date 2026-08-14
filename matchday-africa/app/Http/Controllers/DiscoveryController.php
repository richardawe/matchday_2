<?php
namespace App\Http\Controllers;use App\Models\Player;use Illuminate\Http\Request;
class DiscoveryController extends Controller {
 private const AFRICA=['NGA','GHA','SEN','CIV','MAR','EGY','TUN','ALG','CMR','MLI','GUI','GAB','RSA','ZAM','ZIM','COD','CGO','BFA','BEN','TOG','KEN','UGA','TZA','ANG','MOZ','CPV','GMB','SLE'];
 public function index(Request $r){$q=Player::with('team')->active()->whereIn('nationality_code',self::AFRICA);if($r->filled('country'))$q->where('nationality_code',$r->country);if($r->filled('search'))$q->where('name','like','%'.$r->search.'%');$players=$q->orderBy('name')->paginate(24)->withQueryString();$countries=Player::whereIn('nationality_code',self::AFRICA)->select('nationality_code','nationality')->distinct()->orderBy('nationality')->get();return view('discovery.index',compact('players','countries'));}
 public function show(Player $player){abort_unless(in_array($player->nationality_code,self::AFRICA),404);$player->load('team');return view('discovery.show',compact('player'));}
}
