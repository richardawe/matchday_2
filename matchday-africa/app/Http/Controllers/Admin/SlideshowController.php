<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Slideshow;
use App\Services\Slideshow\Slideshows;
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

    public function index()
    {
        $ready = Slideshow::ready();

        return view('admin.slideshows.index', [
            'ready' => $ready,
            'enabled' => Slideshows::enabled(),
            'shows' => $ready ? Slideshow::with(['match.homeTeam', 'match.awayTeam', 'match.league', 'player'])
                ->where('created_at', '>=', now()->subDays(8))
                ->orderByRaw("case when status = 'ready' and approved = 0 then 0 when status in ('pending', 'rendering') then 1 else 2 end")
                ->orderByDesc('priority')->orderByDesc('id')->limit(120)->get() : collect(),
            'music' => Slideshows::musicFile(),
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

    public function regenerate(Slideshow $slideshow): RedirectResponse
    {
        $this->slideshows->regenerate($slideshow);

        return back()->with('success', "Slideshow {$slideshow->id} will be rendered again from current data.");
    }
}
