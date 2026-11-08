<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SocialQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /admin/social-queue: reels and cards for the posting bot, with each channel's posted state. */
class SocialQueueController extends Controller
{
    public function __construct(private SocialQueue $queue) {}

    public function index()
    {
        $ready = SocialQueue::ready();

        return view('admin.social-queue.index', [
            'ready' => $ready,
            'items' => $ready ? $this->queue->items() : collect(),
            'channels' => SocialQueue::CHANNELS,
            'autopost' => \App\Support\SocialAutopost::enabled(),
            'tokenSet' => (string) config('social.queue_token') !== '',
        ]);
    }

    public function posted(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->queue->markPosted($data['id'], $data['channel'])
            ? back()->with('success', "Marked as posted on {$data['channel']}.")
            : back()->with('error', 'That item is no longer in the queue.');
    }

    public function undo(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->queue->unmark($data['id'], $data['channel']);

        return back()->with('success', "Cleared the {$data['channel']} post.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'id' => ['required', 'string', 'max:100'],
            'channel' => ['required', Rule::in(SocialQueue::CHANNELS)],
        ]);
    }
}
