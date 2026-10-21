<?php

namespace App\Services;

use App\Models\RadioBulletin;
use Illuminate\Support\Str;

class RadioVisuals
{
    public function slides(RadioBulletin $bulletin): array
    {
        $facts = collect($bulletin->facts)->keyBy('key');
        $slides = [];
        foreach ($bulletin->audio_cues ?? [] as $index => $cue) {
            $segment = $bulletin->segments[$index] ?? null;
            if (! $segment || ! is_numeric($cue['start'] ?? null) || ! is_numeric($cue['end'] ?? null)) {
                continue;
            }
            $support = $facts->only($segment['fact_keys']);
            if ($support->isEmpty()) {
                continue;
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
            $slides[] = ['start' => (float) $cue['start'], 'end' => (float) $cue['end'],
                'title' => $support->pluck('headline')->implode(' · '),
                'label' => $support->contains('kind', 'news') ? 'Football news' : ($support->contains('kind', 'result') ? 'Results round-up' : 'Up next'),
                'image' => asset($image), 'alt' => $alt];
        }

        return $slides;
    }
}
