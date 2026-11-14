<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialVideo;
use App\Services\SocialQueue;
use App\Services\SocialVideos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/social-queue/videos: the posting bot uploads a long video (multipart mp4 plus title,
 * caption, campaign, credits, optional featured). It becomes a static file, a page at
 * /reels/videos/{id} and the queue item "video:<id>". Videos of 60 s or less need short=1.
 */
class SocialVideoController extends Controller
{
    public function store(Request $request, SocialVideos $videos, SocialQueue $queue): JsonResponse
    {
        if (!SocialQueue::ready() || !SocialVideo::ready()) {
            return response()->json(['message' => 'Long videos need their migration.'], 503);
        }
        $validator = Validator::make($request->all(), SocialVideos::rules(), SocialVideos::messages());
        if ($validator->fails()) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $file = $request->file('file');
        $seconds = SocialQueue::mp4Seconds((string) $file->getRealPath());
        if ($problem = SocialVideos::lengthProblem($seconds, (bool) ($data['short'] ?? false))) {
            return response()->json(['message' => $problem, 'errors' => ['file' => [$problem]]], 422);
        }
        $video = $videos->create($file, $request->file('poster'), $data, (float) $seconds, null, 'api', 'queued');

        return response()->json($queue->find("video:{$video->id}") ?? ['id' => "video:{$video->id}"], 201);
    }
}
