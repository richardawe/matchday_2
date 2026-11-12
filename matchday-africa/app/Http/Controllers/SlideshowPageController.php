<?php

namespace App\Http\Controllers;

use App\Models\Slideshow;
use App\Services\Griot\GriotChallengeService;
use App\Services\Slideshow\Slideshows;

/** The shareable page for one approved colour slideshow, with credits and the prediction call to action. */
class SlideshowPageController extends Controller
{
    public function show(Slideshow $slideshow)
    {
        abort_unless(Slideshow::ready() && $slideshow->approved && $slideshow->status === 'ready' && $slideshow->videoUrl(), 404);
        $slideshow->load(['match.homeTeam', 'match.awayTeam', 'match.league', 'player.team']);
        $match = $slideshow->match;
        $title = $slideshow->title();
        $subject = data_get($slideshow->facts, 'title', $title);
        $description = trim($subject.'. '.$slideshow->label().' from Matchday Africa, made from the site\'s own match data.');
        $seconds = (int) round($slideshow->duration_seconds ?: 16);
        $cta = $match && !in_array($match->status, ['FINISHED', 'FT', 'AWARDED'], true)
            ? ['label' => 'Call the score', 'href' => route('matches.show', $match).'#prediction-arena']
            : (GriotChallengeService::ready() ? ['label' => 'Beat the Griot', 'href' => route('griot.challenge')]
                : ($match ? ['label' => 'Full story and stats', 'href' => route('matches.show', $match)] : null));
        $more = Slideshow::published()->whereKeyNot($slideshow->id)->latest('approved_at')->limit(6)->get();

        return view('slideshows.show', [
            'show' => $slideshow, 'match' => $match, 'cta' => $cta, 'more' => $more,
            'musicCredit' => Slideshows::musicCredit($slideshow->music, full: true),
        ])->with('content', [
            'title' => "{$subject}: {$slideshow->label()} | Matchday Africa",
            'description' => $description,
            'url' => $slideshow->pageUrl(),
            'type' => 'video.other',
            'image' => $slideshow->posterUrl(),
            'image_width' => '1080', 'image_height' => '1920',
            'video' => $slideshow->videoUrl(),
            'json_ld' => array_filter(['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => "{$subject}: {$slideshow->label()}",
                'description' => $description, 'thumbnailUrl' => array_values(array_filter([$slideshow->posterUrl()])),
                'uploadDate' => ($slideshow->approved_at ?? $slideshow->rendered_at ?? $slideshow->created_at)?->toIso8601String(),
                'duration' => "PT{$seconds}S", 'contentUrl' => $slideshow->videoUrl(), 'embedUrl' => $slideshow->pageUrl(),
                'creditText' => implode('; ', array_filter([Slideshows::musicCredit($slideshow->music), data_get($slideshow->photo, 'credit') ? 'Photo: '.data_get($slideshow->photo, 'credit') : null]))]),
        ]);
    }
}
