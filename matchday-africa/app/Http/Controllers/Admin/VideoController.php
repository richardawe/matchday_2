<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeaturedItem;
use App\Models\RadioBulletin;
use App\Models\Slideshow;
use App\Models\SocialVideo;
use App\Services\RadioVideo;
use App\Services\SocialQueue;
use App\Services\SocialVideos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /admin/videos: long videos for TikTok and the "As seen on TikTok" row on /reels. Upload an mp4,
 * edit its words, feature it, put it on the social queue or take it off; pin approved slideshows and
 * ready radio videos to the front of the row without re-uploading them.
 */
class VideoController extends Controller
{
    public function index(SocialVideos $videos)
    {
        $ready = SocialVideo::ready() && FeaturedItem::ready();

        return view('admin.videos.index', [
            'ready' => $ready,
            'videos' => $ready ? SocialVideo::with('uploader')->latest('id')->limit(100)->get() : collect(),
            'pinned' => $ready ? FeaturedItem::pluck('subject')->all() : [],
            'slideshows' => $ready && Slideshow::ready() ? Slideshow::published()->latest('approved_at')->limit(20)->get()->filter(fn ($s) => $s->videoUrl()) : collect(),
            'radio' => $ready && RadioVideo::columnsReady() ? RadioBulletin::published()->where('video_status', 'ready')->latest('published_at')->limit(10)->get()->filter(fn ($b) => $b->videoReady()) : collect(),
            'row' => $ready ? $videos->row() : collect(),
            'uploadLimit' => ini_get('upload_max_filesize'),
            'postLimit' => ini_get('post_max_size'),
        ]);
    }

    public function store(Request $request, SocialVideos $videos)
    {
        abort_unless(SocialVideo::ready(), 503, 'Run the migrations first.');
        $data = $request->validate(SocialVideos::rules(), SocialVideos::messages());
        $seconds = SocialQueue::mp4Seconds((string) $request->file('file')->getRealPath());
        if ($problem = SocialVideos::lengthProblem($seconds, $request->boolean('short'))) {
            throw ValidationException::withMessages(['file' => $problem]);
        }
        $status = $request->boolean('queue') ? 'queued' : 'approved';
        $video = $videos->create($request->file('file'), $request->file('poster'), $data, (float) $seconds, $request->user()?->id, 'admin', $status);

        return redirect()->route('admin.videos.index')->with('success', "Uploaded \"{$video->title}\"".($status === 'queued' ? ' and put it on the social queue.' : '.'));
    }

    public function update(Request $request, SocialVideo $video)
    {
        $rules = SocialVideos::rules(withFile: false);
        unset($rules['poster'], $rules['short']);
        $data = $request->validate($rules, SocialVideos::messages());
        $video->update([
            'title' => trim($data['title']),
            'caption' => $data['caption'] ?? null,
            'captions' => collect((array) ($data['captions'] ?? []))->only(SocialQueue::CHANNELS)->map(fn ($c) => trim((string) $c))->filter()->all() ?: null,
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? $video->type,
            'campaign' => strtolower($data['campaign'] ?? '') ?: 'documentary',
            'credits' => $data['credits'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'landing_path' => $data['landing_path'] ?? null,
            'tiktok_url' => $data['tiktok_url'] ?? null,
            'youtube_url' => $data['youtube_url'] ?? null,
        ]);

        return back()->with('success', 'Saved.');
    }

    /** approved (page only), queued (page and social queue) or off (taken down everywhere). */
    public function status(Request $request, SocialVideo $video, SocialVideos $videos)
    {
        $data = $request->validate(['status' => ['required', Rule::in(SocialVideo::STATUSES)]]);
        $videos->setStatus($video, $data['status']);

        return back()->with('success', match ($data['status']) {
            'queued' => 'On the social queue.',
            'approved' => 'Off the social queue; the page stays up.',
            default => 'Taken down: no page, no row, no queue item.',
        });
    }

    public function feature(Request $request, SocialVideo $video)
    {
        $video->update(['featured' => $request->boolean('featured')]);

        return back()->with('success', $video->featured ? 'Featured on /reels.' : 'No longer featured.');
    }

    /** Pin or unpin an approved slideshow or ready radio video ("slideshow:<id>", "radio:<id>"). */
    public function pin(Request $request)
    {
        abort_unless(FeaturedItem::ready(), 503, 'Run the migrations first.');
        $data = $request->validate(['subject' => ['required', 'string', 'regex:/^(slideshow|radio):[0-9]+$/'], 'pinned' => ['required', 'boolean']]);
        if ($request->boolean('pinned')) {
            FeaturedItem::firstOrCreate(['subject' => $data['subject']]);
        } else {
            FeaturedItem::where('subject', $data['subject'])->delete();
        }

        return back()->with('success', $request->boolean('pinned') ? 'Pinned to the front of the row.' : 'Unpinned.');
    }
}
