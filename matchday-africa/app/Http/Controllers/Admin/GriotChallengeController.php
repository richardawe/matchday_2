<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GriotChallenge;
use App\Models\GriotChallengeWinner;
use App\Services\Griot\GriotChallengeService;
use Illuminate\Http\Request;

/** Beat the Griot desk: each round's calls and counts, and the drawn winners to credit by hand. */
class GriotChallengeController extends Controller
{
    public function index()
    {
        $ready = GriotChallengeService::tablesReady();

        return view('admin.griot.index', [
            'ready' => $ready,
            'enabled' => (bool) config('griot_challenge.enabled'),
            'challenges' => $ready
                ? GriotChallenge::with(['picks.match.homeTeam', 'picks.match.awayTeam', 'winners.user', 'winners.creditedBy'])->latest('id')->limit(12)->get()
                : collect(),
        ]);
    }

    public function credit(Request $request, GriotChallengeWinner $winner)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'network' => 'required|in:'.implode(',', config('griot_challenge.networks')),
            'note' => 'nullable|string|max:500',
        ]);
        $winner->update($data + ['credited_at' => now(), 'credited_by' => $request->user()->id]);

        return back()->with('success', 'Marked '.$winner->code.' as credited.');
    }
}
