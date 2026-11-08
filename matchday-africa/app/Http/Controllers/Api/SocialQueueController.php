<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocialQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** The social queue for the posting bot: what to post, and where it has been posted. */
class SocialQueueController extends Controller
{
    public function __construct(private SocialQueue $queue) {}

    public function index(): JsonResponse
    {
        if (!SocialQueue::ready()) {
            return response()->json(['message' => 'The social queue needs its migration.'], 503);
        }

        return response()->json([
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'days' => (int) config('social.queue_days', 14),
            'items' => $this->queue->items()->values(),
        ]);
    }

    public function posted(Request $request, string $id): JsonResponse
    {
        if (!SocialQueue::ready()) {
            return response()->json(['message' => 'The social queue needs its migration.'], 503);
        }
        $validator = Validator::make($request->all(), [
            'channel' => ['required', 'string', Rule::in(SocialQueue::CHANNELS)],
            'external_post_id' => ['nullable', 'string', 'max:191'],
            'url' => ['nullable', 'string', 'url', 'max:2048'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $item = $this->queue->markPosted($id, $data['channel'], $data['external_post_id'] ?? null, $data['url'] ?? null);

        return $item ? response()->json($item) : response()->json(['message' => 'Unknown queue item.'], 404);
    }
}
