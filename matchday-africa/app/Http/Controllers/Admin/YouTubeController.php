<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\UploadReelToYouTube;
use App\Models\FootballMatch;
use App\Models\YoutubeBroadcast;
use App\Services\ReelService;
use App\Services\YouTube\ReelUploads;
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
            'windows' => $ready ? $this->broadcasts->windows(min: 1) : collect(),
            'broadcasts' => $ready ? YoutubeBroadcast::latest('starts_at')->limit(20)->get() : collect(),
            'streamPage' => (bool) config('stream.enabled'),
            'canUpload' => $account ? YouTubeClient::canUpload($account) : false,
            'wrongChannel' => $account && config('youtube.channel_id') && $account->channel_id !== config('youtube.channel_id'),
            'uploadsOn' => (bool) config('youtube.uploads.enabled'),
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
        $window = $this->broadcasts->windows(min: 1)->first(fn ($w) => $key === '' || $w['key'] === $key);
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

    /** /admin/youtube/uploads: recent reels and their YouTube Shorts. */
    public function uploads(ReelUploads $uploads, ReelService $reels)
    {
        $ready = ReelUploads::ready();
        $matches = $ready
            ? FootballMatch::with(['league', 'homeTeam', 'awayTeam'])
                ->where(fn ($q) => $q->where('match_date', '>=', now('UTC')->subDays(14))->orWhereIn('youtube_status', ['pending', 'uploading', 'failed']))
                ->where(fn ($q) => $q->where('metadata->reel->status', 'ready')->orWhereNotNull('youtube_status'))
                ->orderByDesc('match_date')->limit(100)->get()
            : collect();

        return view('admin.youtube.uploads', [
            'ready' => $ready,
            'enabled' => $uploads->enabled(),
            'switchedOn' => (bool) config('youtube.uploads.enabled'),
            'account' => $uploads->account(),
            'matches' => $matches,
            'hasReel' => fn (FootballMatch $m) => $reels->path($m) !== null,
            'used' => $uploads->usedToday(),
            'left' => $uploads->left(),
            'daily' => (int) config('youtube.uploads.daily_uploads'),
            'reset' => $uploads->quotaReset(),
        ]);
    }

    /** Queues one reel again (attempts reset) and sends the upload job. */
    public function retry(FootballMatch $match, ReelUploads $uploads)
    {
        abort_unless(ReelUploads::ready(), 404);
        if (!$uploads->enabled()) {
            return back()->with('error', 'Uploads are off (YOUTUBE_UPLOADS) or no channel that may upload is connected.');
        }
        if (!$uploads->queue($match)) {
            return back()->with('error', $match->youtube_video_id ? 'That reel is already on YouTube.' : 'That match has no ready reel.');
        }
        UploadReelToYouTube::dispatch($match->id);

        return back()->with('success', $uploads->left() > 0
            ? 'Queued: it uploads within five minutes.'
            : 'Queued: today\'s upload quota is used, so it goes up after '.$uploads->quotaReset()->setTimezone('Africa/Lagos')->format('D H:i').' Lagos.');
    }
}
