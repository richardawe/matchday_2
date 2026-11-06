<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\YoutubeBroadcast;
use App\Services\YouTube\YouTubeBroadcasts;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** /admin/youtube: connect the channel once, see the stream key and broadcasts, schedule or test. */
class YouTubeController extends Controller
{
    public function __construct(private YouTubeClient $client, private YouTubeBroadcasts $broadcasts) {}

    public function index()
    {
        $ready = YouTubeClient::ready();
        $account = YouTubeClient::account();
        $health = null;
        if ($account && YouTubeClient::configured()) {
            try {
                $health = $this->client->streamStatus($account);
            } catch (\Throwable $e) {
                $health = 'error: '.$e->getMessage();
            }
        }

        return view('admin.youtube.index', [
            'ready' => $ready,
            'configured' => YouTubeClient::configured(),
            'account' => $account,
            'health' => $health,
            'auto' => (bool) config('youtube.auto'),
            'privacy' => config('youtube.privacy'),
            'redirect' => route('admin.youtube.callback'),
            'windows' => $ready ? $this->broadcasts->windows() : collect(),
            'broadcasts' => $ready ? YoutubeBroadcast::latest('starts_at')->limit(20)->get() : collect(),
            'streamPage' => (bool) config('stream.enabled'),
        ]);
    }

    public function connect(Request $request)
    {
        abort_unless(YouTubeClient::ready() && YouTubeClient::configured(), 404);
        $state = Str::random(40);
        $request->session()->put('youtube_oauth_state', $state);

        return redirect()->away($this->client->authUrl(route('admin.youtube.callback'), $state));
    }

    public function callback(Request $request)
    {
        abort_unless(YouTubeClient::ready() && YouTubeClient::configured(), 404);
        $expected = $request->session()->pull('youtube_oauth_state');
        if (!$expected || !hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('admin.youtube.index')->with('error', 'That sign-in link has expired. Press Connect YouTube again.');
        }
        if ($request->query('error')) {
            return redirect()->route('admin.youtube.index')->with('error', 'Google sign-in was cancelled ('.Str::limit((string) $request->query('error'), 60).').');
        }
        try {
            $account = $this->client->connect((string) $request->query('code'), route('admin.youtube.callback'), $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()->route('admin.youtube.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.youtube.index')->with('success', "Connected {$account->channel_title}.");
    }

    public function disconnect()
    {
        if ($account = YouTubeClient::account()) {
            $this->client->disconnect($account);
        }

        return redirect()->route('admin.youtube.index')->with('success', 'YouTube is disconnected. Broadcasts already made stay on the channel.');
    }

    /** Makes the broadcast for the next matchday now (or for a given window key). */
    public function schedule(Request $request)
    {
        $account = YouTubeClient::account();
        abort_unless($account && YouTubeClient::configured(), 404);
        $key = (string) $request->input('window');
        $window = $this->broadcasts->windows()->first(fn ($w) => $key === '' || $w['key'] === $key);
        if (!$window) {
            return back()->with('error', 'There is no matchday in the next 48 hours.');
        }
        if (YoutubeBroadcast::where('window_key', $window['key'])->exists()) {
            return back()->with('error', 'That matchday already has a broadcast.');
        }
        try {
            $broadcast = $this->broadcasts->schedule($account, $window);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Scheduled \"{$broadcast->title}\".");
    }

    public function test()
    {
        $account = YouTubeClient::account();
        abort_unless($account && YouTubeClient::configured(), 404);
        try {
            $broadcast = $this->broadcasts->test($account);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Private test broadcast ready for 30 minutes: start streaming to the key below, then open it in YouTube Studio ({$broadcast->studioUrl()}).");
    }
}
