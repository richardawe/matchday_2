<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\MatchAlert;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\InboxMessage;
use App\Services\Alerts\Alerts;
use App\Services\Griot\GriotChallengeService;
use Illuminate\Http\Request;

/** Messages from Matchday Africa to users: in every recipient's inbox, and by push if asked. */
class AnnouncementController extends Controller
{
    public function __construct(private Alerts $alerts) {}

    public function index()
    {
        abort_unless($this->alerts->ready(), 404, 'Run the migrations first.');

        return view('admin.messages.index', [
            'announcements' => Announcement::with('author')->latest()->limit(50)->get(),
            'counts' => collect(array_keys(Announcement::AUDIENCES))->mapWithKeys(fn ($a) => [$a => $this->audience($a)->count()]),
            'pushReady' => $this->alerts->pushReady(),
            'devices' => PushSubscription::count(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->alerts->ready(), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'url' => ['nullable', 'url', 'max:500'],
            'audience' => ['required', 'in:'.implode(',', array_keys(Announcement::AUDIENCES))],
            'push' => ['nullable', 'boolean'],
        ]);
        $push = $request->boolean('push') && $this->alerts->pushReady();
        $users = $this->audience($data['audience'])->get();
        $result = $this->alerts->deliver($users, new InboxMessage('announcement', $data['title'], $data['body'], $data['url'] ?? null), push: $push, topic: 'announcement');
        Announcement::create($data + ['pushed' => $push, 'recipients' => $result['inbox'], 'created_by' => $request->user()->id]);

        return back()->with('success', "Sent to {$result['inbox']} inboxes".($push ? " and {$result['pushed']} devices" : '').'.');
    }

    private function audience(string $audience)
    {
        return match ($audience) {
            'alerts' => User::whereIn('id', MatchAlert::where('created_at', '>=', now()->subDays(30))->select('user_id')),
            'push' => User::whereIn('id', PushSubscription::select('user_id')),
            'griot' => GriotChallengeService::tablesReady() ? GriotChallengeService::everyCaller() : User::whereRaw('1 = 0'),
            default => User::query(),
        };
    }
}
