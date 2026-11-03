<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\MatchAlert;
use App\Models\PushSubscription;
use App\Services\Alerts\Alerts;
use App\Services\Alerts\MatchAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Alert me" on a match, push subscriptions, and the inbox. Alerts need an account. */
class AlertController extends Controller
{
    public function __construct(private Alerts $alerts, private MatchAlerts $matches) {}

    public function follow(Request $request, FootballMatch $match): JsonResponse
    {
        abort_unless($this->alerts->ready(), 404);
        $on = $request->boolean('on', true);
        $on ? $this->matches->follow($request->user(), $match) : $this->matches->unfollow($request->user(), $match);

        return response()->json(['on' => $on, 'push' => $this->alerts->pushReady(),
            'devices' => PushSubscription::where('user_id', $request->user()->id)->count()]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless($this->alerts->pushReady(), 404);
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+=*$/'],
            'keys.auth' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+=*$/'],
        ]);
        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'p256dh' => rtrim($data['keys']['p256dh'], '='), 'auth' => rtrim($data['keys']['auth'], '='),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'failures' => 0,
        ]);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        PushSubscription::where(['user_id' => $request->user()->id, 'endpoint_hash' => hash('sha256', $request->input('endpoint'))])->delete();

        return response()->json(['ok' => true]);
    }

    public function inbox(Request $request)
    {
        abort_unless($this->alerts->ready(), 404);
        $notifications = $request->user()->notifications()->paginate(30);
        $alerts = MatchAlert::with(['match.homeTeam', 'match.awayTeam'])->where('user_id', $request->user()->id)
            ->whereHas('match', fn ($q) => $q->where('match_date', '>=', now('UTC')->subDay()->format('Y-m-d H:i:s')))->get();

        return view('notifications.index', compact('notifications', 'alerts') + ['pushReady' => $this->alerts->pushReady()]);
    }

    public function read(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    /** Opens an inbox entry: marks it read and follows its link. */
    public function open(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        $url = (string) data_get($notification->data, 'url');

        return redirect()->to(str_starts_with($url, url('/')) ? $url : route('notifications.index'));
    }
}
