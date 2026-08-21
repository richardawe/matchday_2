<?php
namespace App\Http\Controllers\War;
use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use Illuminate\Http\Request;

class WarController extends Controller
{
    public function index(Request $request) { return view('war.index', ['initialMatchId' => $request->integer('fixture')]); }
    public function match(FootballMatch $match) { $match->loadMissing(['homeTeam','awayTeam']); return view('war.index', ['initialMatchId' => $match->id, 'initialHome' => $match->homeTeam?->name, 'initialAway' => $match->awayTeam?->name]); }
    public function challenge(FootballMatch $match) { $match->loadMissing(['homeTeam','awayTeam']); return view('war.index', ['initialMatchId' => $match->id, 'initialHome' => $match->homeTeam?->name, 'initialAway' => $match->awayTeam?->name, 'challenge' => true]); }
}
