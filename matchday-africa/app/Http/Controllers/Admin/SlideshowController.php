<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Slideshow;
use App\Services\Slideshow\CommonsPhotos;
use App\Services\Slideshow\Slideshows;
use App\Services\Slideshow\SlideshowVideo;
use App\Support\XText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /admin/slideshows: colour slideshows waiting for review. Approving one puts it in the social
 * queue API and queues its YouTube Short; regenerating renders it again from current data.
 */
class SlideshowController extends Controller
{
    public function __construct(private Slideshows $slideshows) {}

    public function index(SlideshowVideo $video)
    {
        $ready = Slideshow::ready();
        $music = Slideshows::musicFile();

        return view('admin.slideshows.index', [
            'ready' => $ready,
            'enabled' => Slideshows::enabled(),
            'shows' => $ready ? Slideshow::with(['match.homeTeam', 'match.awayTeam', 'match.league', 'player'])
                ->where('created_at', '>=', now()->subDays(8))
                ->orderByRaw("case when status = 'ready' and approved = 0 then 0 when status in ('pending', 'rendering') then 1 else 2 end")
                ->orderByDesc('priority')->orderByDesc('id')->limit(120)->get() : collect(),
            'music' => $music,
            'musicCredit' => Slideshows::musicCredit(Slideshows::music(), full: true),
            'musicChecked' => is_file($music) && ($sha = (string) config('slideshow.music.tracks.'.config('slideshow.music.track').'.sha256')) !== '' && hash_file('sha256', $music) === $sha,
            'musicInfo' => Slideshows::music() + Slideshows::uploaded(),
            'ffmpeg' => $video->ffmpeg(),
            'ffmpegMissing' => $video->missing($music),
            'videoMode' => $video->mode(),
            'uploadLimit' => min($this->bytes(ini_get('upload_max_filesize')), $this->bytes(ini_get('post_max_size'))),
            'xLimit' => XText::LIMIT,
        ]);
    }

    public function approve(Request $request, Slideshow $slideshow): RedirectResponse
    {
        return $this->slideshows->approve($slideshow, $request->user())
            ? back()->with('success', "Slideshow {$slideshow->id} approved: it is in the social queue and queued for YouTube.")
            : back()->with('error', 'Only a rendered slideshow can be approved.');
    }

    public function unapprove(Request $request, Slideshow $slideshow): RedirectResponse
    {
        $this->slideshows->unapprove($slideshow, $request->user());

        return back()->with('success', "Slideshow {$slideshow->id} taken off the queue.");
    }

    /** Uploads the licensed music bed (kept in storage/app/music, never public) and where it came from. */
    public function music(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'track' => ['required', 'file', 'max:40960', 'mimes:mp3,m4a,aac,wav,flac,ogg,mpga,mp4'],
            'source_url' => ['nullable', 'url', 'max:2048'],
        ]);
        Slideshows::storeMusic($request->file('track'), $data['source_url'] ?? null, $request->user()?->id);

        return back()->with('success', 'Music uploaded. New renders use it; failed renders retry on their own.');
    }

    /** Pins a Commons file for a player's slide 01 photo (the licence is checked first), or looks it up again. */
    public function photo(Request $request, CommonsPhotos $photos): RedirectResponse
    {
        $data = $request->validate(['player_id' => ['required', 'integer', 'exists:players,id'], 'file' => ['nullable', 'string', 'max:255']]);
        $player = Player::findOrFail($data['player_id']);
        $file = trim((string) ($data['file'] ?? ''));
        if ($file !== '' && preg_match('#commons\.wikimedia\.org/wiki/(File:[^?\#]+)#i', $file, $m)) {
            $file = rawurldecode(str_replace('_', ' ', $m[1]));
        }
        $photo = $photos->lookup($player, $file !== '' ? $file : null);

        return $photo
            ? back()->with('success', "{$player->name}: {$photo['title']} ({$photo['licence']}). Regenerate the slideshow to use it.")
            : back()->with('error', "{$player->name}: ".data_get($player->fresh()->metadata, 'commons_photo.reason', 'the lookup failed; try again later.'));
    }

    public function regenerate(Slideshow $slideshow): RedirectResponse
    {
        $this->slideshows->regenerate($slideshow);

        return back()->with('success', "Slideshow {$slideshow->id} will be rendered again from current data.");
    }

    private function bytes(string|false $value): int
    {
        $value = trim((string) $value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3, 'm' => $number * 1024 ** 2, 'k' => $number * 1024, default => $number,
        } ?: PHP_INT_MAX;
    }
}
