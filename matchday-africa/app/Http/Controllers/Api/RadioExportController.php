<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RadioBulletin;
use App\Services\RadioVideo;
use App\Services\SocialQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The posting bot's view of Matchday Radio (same bearer token as the social queue): a day's published
 * editions with their mp3 and vertical mp4, and downloads of both. Drafts, held (withdrawn) and failed
 * editions are never listed or served.
 */
class RadioExportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = (string) $request->query('date', now('Africa/Lagos')->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !Carbon::canBeCreatedFromFormat($date, 'Y-m-d')) {
            return response()->json(['message' => 'date must be YYYY-MM-DD (Africa/Lagos).'], 422);
        }
        $editions = RadioBulletin::available()
            ? RadioBulletin::published()->where('slot', 'like', $date.'-%')->orderBy('slot')->get()->filter(fn ($b) => $b->audioFile())
            : collect();
        $video = RadioVideo::columnsReady();

        return response()->json([
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'date' => $date,
            'timezone' => 'Africa/Lagos',
            'video_enabled' => RadioVideo::ready(),
            'editions' => $editions->map(fn (RadioBulletin $b) => $this->edition($b, $video))->values(),
        ]);
    }

    public function audio(string $edition)
    {
        $edition = $this->published($edition);
        abort_unless($file = $edition?->audioFile(), 404);

        return response()->download($file, "matchday-radio-{$edition->slot}.mp3", ['Content-Type' => 'audio/mpeg']);
    }

    public function video(string $edition)
    {
        $edition = $this->published($edition);
        abort_unless($edition && RadioVideo::columnsReady() && $edition->videoReady(), 404);

        return response()->download($edition->publicVideoPath(), "matchday-radio-{$edition->slot}.mp4", ['Content-Type' => 'video/mp4']);
    }

    private function published(string $id): ?RadioBulletin
    {
        return RadioBulletin::available() && ctype_digit($id) ? RadioBulletin::published()->find((int) $id) : null;
    }

    private function edition(RadioBulletin $b, bool $video): array
    {
        $ready = $video && $b->videoReady();

        return [
            'id' => $b->id,
            'queue_id' => "radio:{$b->id}",
            'slot' => $b->slot,
            'edition' => $b->editionLabel(),
            'title' => $b->title,
            'duration_seconds' => (int) $b->duration_seconds,
            'recorded_at' => $b->recorded_at?->copy()->utc()->toIso8601ZuluString(),
            'published_at' => $b->published_at?->copy()->utc()->toIso8601ZuluString(),
            'page_url' => SocialQueue::https(route('radio.show', $b)),
            'landing_urls' => RadioVideo::landingUrls($b),
            'mp3_url' => SocialQueue::https($b->audioUrl()),
            'mp3_download_url' => SocialQueue::https(route('api.radio-export.audio', $b->id)),
            'mp4_url' => $ready ? SocialQueue::https($b->videoUrl()) : null,
            'mp4_download_url' => $ready ? SocialQueue::https(route('api.radio-export.video', $b->id)) : null,
            'video_status' => $video ? ($b->video_status ?? 'waiting') : 'off',
            'video_duration_seconds' => $ready ? $b->video_seconds : null,
            'poster_url' => $ready ? SocialQueue::https($b->posterUrl()) : null,
            'caption' => RadioVideo::captions($b)['instagram'],
            'captions' => RadioVideo::captions($b),
            'disclosure' => (string) config('radio.disclosure'),
        ];
    }
}
