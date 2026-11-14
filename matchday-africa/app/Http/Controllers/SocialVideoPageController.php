<?php

namespace App\Http\Controllers;

use App\Models\SocialVideo;
use App\Services\SocialQueue;
use App\Services\SocialVideos;
use App\Services\StartPage;
use Illuminate\Support\Str;

/** /reels/videos/{id}: the page for a long video (approved or on the queue only). */
class SocialVideoPageController extends Controller
{
    public function show(SocialVideo $video, SocialVideos $videos, StartPage $start)
    {
        abort_unless(SocialVideo::ready() && $video->isPublic(), 404);
        $primary = $start->primary([]);
        $cta = $primary['href'] ? $primary : null;
        $more = $videos->row()->reject(fn ($c) => $c['key'] === "video:{$video->id}")->take(6)->values();
        $summary = $video->description ?: ($video->caption ?: "{$video->title}, a ".Str::lower($video->typeLabel()).' from Matchday Africa.');
        $description = Str::limit(preg_replace('/\s+/', ' ', SocialQueue::plain($summary)), 155);

        return view('reels.video', ['video' => $video, 'cta' => $cta, 'more' => $more])->with('content', [
            'title' => "{$video->title}: ".Str::lower($video->typeLabel())." | Matchday Africa",
            'description' => $description,
            'url' => $video->pageUrl(),
            'type' => 'video.other',
            'image' => $video->posterUrl() ? SocialQueue::https($video->posterUrl()) : null,
            'video' => $video->videoUrl(),
            'json_ld' => array_filter(['@context' => 'https://schema.org', '@type' => 'VideoObject', 'name' => $video->title,
                'description' => $description, 'thumbnailUrl' => $video->posterUrl() ? [SocialQueue::https($video->posterUrl())] : null,
                'uploadDate' => $video->created_at?->toIso8601String(),
                'duration' => $video->duration_seconds ? 'PT'.(int) round($video->duration_seconds).'S' : null,
                'contentUrl' => $video->videoUrl(), 'embedUrl' => $video->pageUrl()]),
        ]);
    }
}
