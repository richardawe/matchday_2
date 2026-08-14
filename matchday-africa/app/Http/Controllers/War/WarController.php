<?php
namespace App\Http\Controllers\War;
use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use Illuminate\Http\Request;

class WarController extends Controller
{
    public function index(Request $request) { return view('war.index', ['initialMatchId' => $request->integer('fixture')]); }
    public function match(FootballMatch $match) { return view('war.index', ['initialMatchId' => $match->id]); }
    public function challenge(FootballMatch $match) { return view('war.index', ['initialMatchId' => $match->id, 'challenge' => true]); }
}
