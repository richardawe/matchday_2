<?php

namespace App\Services;

use App\Models\RadioBulletin;
use Illuminate\Support\Str;

/**
 * Timed story slides for a bulletin: one per voiced segment, from the measured audio_cues, with only
 * the site's own rights-safe artwork (army art for a club named in the segment, else a pitch drawing).
 * Used by the listen player and the vertical radio video.
 */
class RadioVisuals
{
    public function slides(RadioBulletin $bulletin): array
    {
        $slides = [];
        foreach ($bulletin->audio_cues ?? [] as $index => $cue) {
            if (! is_numeric($cue['start'] ?? null) || ! is_numeric($cue['end'] ?? null)) {
                continue;
            }
            $slide = $this->segment($bulletin, $index);
            if (! $slide) {
                continue;
            }
            $slides[] = ['start' => (float) $cue['start'], 'end' => (float) $cue['end'],
                'title' => $slide['title'], 'label' => $slide['label'], 'image' => asset($slide['image']), 'alt' => $slide['alt']];
        }

        return $slides;
    }

    /**
     * The slide for segment $index: title, label, public image path (relative to public/) and alt text.
     * Null when the segment has no supporting facts.
     */
    public function segment(RadioBulletin $bulletin, int $index): ?array
    {
        $segment = $bulletin->segments[$index] ?? null;
        if (! $segment) {
            return null;
        }
        $support = collect($bulletin->facts)->keyBy('key')->only($segment['fact_keys'] ?? []);
        if ($support->isEmpty()) {
            return null;
        }
        $text = Str::lower($support->pluck('text')->implode(' '));
        $image = 'images/radio/football.svg';
        $alt = 'Football pitch illustration';
        foreach (config('armies.clubs') as $club => $army) {
            $path = 'war/army/'.$army['art'].'-480.webp';
            if ($army['art'] && Str::contains($text, $club) && is_file(public_path($path))) {
                $image = $path;
                $alt = 'Matchday artwork for '.Str::title($club);
                break;
            }
        }

        return ['title' => $support->pluck('headline')->implode(' · '),
            'label' => $support->contains('kind', 'news') ? 'Football news' : ($support->contains('kind', 'result') ? 'Results round-up' : 'Up next'),
            'image' => $image, 'alt' => $alt];
    }
}
