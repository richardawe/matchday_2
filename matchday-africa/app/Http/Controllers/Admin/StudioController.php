<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Models\RadioBulletin;
use App\Models\StoryVideo;
use App\Services\SocialQueue;
use App\Services\Story\StoryVideos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * /admin/studio, the Content studio: finished matches and radio editions with "Render story",
 * render status, preview, and editable hook, banter and captions. Open to admins and the agent role.
 */
class StudioController extends Controller
{
    public function __construct(private StoryVideos $stories) {}

    public function index(Request $request)
    {
        $ready = StoryVideo::ready();
        $days = min(14, max(1, (int) $request->query('days', 3)));
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])->whereNotNull('home_score')
            ->where('match_date', '>=', now('UTC')->subDays($days)->format('Y-m-d H:i:s'))
            ->orderByDesc('match_date')->limit(150)->get()
            ->sortBy(fn ($m) => $this->stories->covered($m) ? 0 : 1)->values();
        $stories = $ready ? StoryVideo::whereIn('match_id', $matches->pluck('id'))->where('kind', 'match_story')->get()->groupBy('match_id') : collect();
        $editions = Schema::hasTable('radio_bulletins') ? RadioBulletin::published()->where('published_at', '>=', now()->subDays($days))->orderByDesc('published_at')->get() : collect();
        $radioStories = $ready ? StoryVideo::whereIn('radio_bulletin_id', $editions->pluck('id'))->where('kind', 'radio_video')->get()->groupBy('radio_bulletin_id') : collect();

        return view('admin.studio.index', [
            'ready' => $ready, 'enabled' => StoryVideos::enabled(), 'days' => $days,
            'matches' => $matches, 'stories' => $stories, 'editions' => $editions, 'radioStories' => $radioStories,
            'music' => StoryVideos::musicFile(), 'missing' => $ready ? $this->stories->missing() : [],
            'languages' => (array) config('story.languages', ['en']), 'channels' => ['tiktok', 'instagram', 'x', 'youtube'],
        ]);
    }

    /** "Render story": queues (or re-queues) a match story or radio video with optional hook and banter. */
    public function render(Request $request): RedirectResponse
    {
        if (!StoryVideos::enabled()) {
            return back()->with('error', 'Story videos are off: set STORY_VIDEOS=true (and run the migrations).');
        }
        $data = $request->validate([
            'kind' => ['required', Rule::in(StoryVideo::KINDS)],
            'subject_id' => ['required', 'integer'],
            'lang' => ['nullable', Rule::in((array) config('story.languages', ['en']))],
            'hook' => ['nullable', 'string', 'max:120'],
            'banter' => ['nullable', 'string', 'max:180'],
        ]);
        if ($data['kind'] === 'match_story') {
            $match = FootballMatch::find($data['subject_id']);
            if (!$match || !in_array($match->status, ['FINISHED', 'FT', 'AWARDED'], true)) {
                return back()->with('error', 'Stories are made for finished matches only.');
            }
        } elseif (!RadioBulletin::published()->find($data['subject_id'])) {
            return back()->with('error', 'That radio edition is not published.');
        }
        $overrides = [];
        foreach (['hook', 'banter'] as $field) {
            if ($request->has($field)) {
                $overrides[$field] = (string) $request->input($field);
            }
        }
        $story = $this->stories->request($data['kind'], (int) $data['subject_id'], $data['lang'] ?? 'en', $overrides, 'admin', $request->user()->id);

        return back()->with('success', "Queued {$story->queueId()}: it renders within a few minutes (story:render runs every minute).");
    }

    /** Edits the hook, banter and captions; re-renders when asked (on-screen text changes need a render). */
    public function update(Request $request, StoryVideo $story): RedirectResponse
    {
        $data = $request->validate([
            'hook' => ['nullable', 'string', 'max:120'],
            'banter' => ['nullable', 'string', 'max:180'],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:5000'],
            'rerender' => ['nullable', 'boolean'],
        ]);
        $captions = collect($data['captions'] ?? [])->only(SocialQueue::CHANNELS)->map(fn ($c) => trim((string) $c))->filter()->all();
        if ($captions && $captions != collect((array) $story->captions)->only(array_keys($captions))->all()) {
            $story->captions = array_merge((array) $story->captions, $captions);
            $story->captions_edited = true;
        }
        $story->hook = trim((string) ($data['hook'] ?? '')) ?: null;
        $story->banter = trim((string) ($data['banter'] ?? '')) ?: null;
        $onScreen = $story->isDirty(['hook', 'banter']);
        $story->save();
        if (!empty($data['rerender']) || $onScreen) {
            if (!StoryVideos::enabled()) {
                return back()->with('error', 'Saved, but story videos are off (STORY_VIDEOS), so it was not re-rendered.');
            }
            $story->update(['status' => 'pending', 'attempts' => 0, 'error' => null]);

            return back()->with('success', "Saved; {$story->queueId()} is drawn again with the new text.");
        }

        return back()->with('success', 'Captions saved.');
    }
}
