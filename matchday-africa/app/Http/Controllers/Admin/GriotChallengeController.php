<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\GriotChallenge;
use App\Models\GriotChallengeWinner;
use App\Notifications\InboxMessage;
use App\Services\Alerts\Alerts;
use App\Services\Griot\GriotChallengeService;
use Illuminate\Http\Request;

/**
 * Beat the Griot desk: each round's calls, the fans who made them, the drawn winners to credit by
 * hand, and in-app messages to a round's fans or to everyone who has ever played.
 */
class GriotChallengeController extends Controller
{
    public function index(GriotChallengeService $service, Alerts $alerts)
    {
        $ready = GriotChallengeService::tablesReady();
        $challenges = $ready
            ? GriotChallenge::with(['picks.match.homeTeam', 'picks.match.awayTeam', 'winners.user', 'winners.creditedBy'])->latest('id')->limit(12)->get()
            : collect();

        return view('admin.griot.index', [
            'ready' => $ready,
            'enabled' => (bool) config('griot_challenge.enabled'),
            'challenges' => $challenges,
            'callers' => $challenges->mapWithKeys(fn (GriotChallenge $c) => [$c->id => $service->callers($c)]),
            'guests' => $challenges->mapWithKeys(fn (GriotChallenge $c) => [$c->id => $service->guestCallers($c)]),
            'everyone' => $ready ? GriotChallengeService::everyCaller()->count() : 0,
            'inbox' => $alerts->ready(),
            'pushReady' => $alerts->pushReady(),
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

    /** An in-app message (and optional push) to one round's fans, or with no round to everyone who has played. */
    public function message(Request $request, Alerts $alerts, GriotChallengeService $service, ?GriotChallenge $challenge = null)
    {
        abort_unless(GriotChallengeService::tablesReady() && $alerts->ready(), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'url' => ['nullable', 'url', 'max:500'],
            'push' => ['nullable', 'boolean'],
        ]);
        $users = $challenge ? $service->callers($challenge)->pluck('user') : GriotChallengeService::everyCaller()->get();
        $push = $request->boolean('push') && $alerts->pushReady();
        $result = $alerts->deliver($users, new InboxMessage('announcement', $data['title'], $data['body'], $data['url'] ?? route('griot.challenge')),
            push: $push, topic: 'griot-challenge');
        Announcement::create([
            'title' => $data['title'], 'body' => $data['body'], 'url' => $data['url'] ?? null,
            'audience' => $challenge ? "griot:{$challenge->id}" : 'griot', 'pushed' => $push,
            'recipients' => $result['inbox'], 'created_by' => $request->user()->id,
        ]);
        $who = $challenge ? "round #{$challenge->id}'s fans" : 'everyone who has played';

        return back()->with('success', "Sent to {$result['inbox']} of {$who}".($push ? ", {$result['pushed']} phones by push" : '').'.');
    }
}
