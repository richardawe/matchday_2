<?php

namespace App\Http\Controllers;

use App\Models\RadioBulletin;

class RadioController extends Controller
{
    public function index()
    {
        abort_unless(RadioBulletin::available(), 404);
        $bulletin = RadioBulletin::latestPlayable();
        $editions = RadioBulletin::published()->orderByDesc('slot')->limit(30)->get()->filter(fn ($b) => $b->audioFile());

        return view('radio.index', compact('bulletin', 'editions'));
    }

    public function show(RadioBulletin $bulletin)
    {
        $this->published($bulletin);

        return view('radio.show', compact('bulletin'));
    }

    public function audio(RadioBulletin $bulletin)
    {
        $this->published($bulletin);
        $file = $bulletin->audioFile();

        return request()->boolean('download')
            ? response()->download($file, 'matchday-'.$bulletin->slot.'.mp3', ['Content-Type' => 'audio/mpeg'])
            : response()->file($file, ['Content-Type' => 'audio/mpeg'])->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);
    }

    public function feed()
    {
        abort_unless(RadioBulletin::available(), 404);
        $editions = RadioBulletin::published()->orderByDesc('slot')->limit(50)->get()->filter(fn ($b) => $b->audioFile());

        return response()->view('radio.feed', compact('editions'))->header('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->header('Cache-Control', 'no-cache');
    }

    private function published(RadioBulletin $bulletin): void
    {
        abort_unless(RadioBulletin::available() && $bulletin->status === 'published' && $bulletin->published_at
            && $bulletin->published_at->lte(now()) && $bulletin->audioFile(), 404);
    }
}
