<?php

namespace App\Http\Controllers;

use App\Services\SocialSharingService;
use App\Models\FootballMatch;
use App\Models\Blog;
use App\Models\PredictionSet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class SocialSharingController extends Controller
{
    protected $socialSharingService;

    public function __construct(SocialSharingService $socialSharingService)
    {
        $this->socialSharingService = $socialSharingService;
    }

    /**
     * Share content on social media
     */
    public function share(Request $request, string $type, int $id, string $platform): RedirectResponse
    {
        try {
            $content = $this->getContent($type, $id);
            
            if (!$content) {
                return redirect()->back()->with('error', 'Content not found.');
            }

            $shareUrl = $this->socialSharingService->generateShareUrl($content, $platform);
            
            // Track the share if user is authenticated
            if (auth()->check()) {
                $this->socialSharingService->trackShare($content, $platform, $shareUrl);
            }

            return redirect($shareUrl);
        } catch (\Exception $e) {
            Log::error('Social sharing failed: ' . $e->getMessage(), [
                'type' => $type,
                'id' => $id,
                'platform' => $platform,
            ]);

            return redirect()->back()->with('error', 'Failed to share content. Please try again.');
        }
    }

    /**
     * Get share counts for content
     */
    public function getShareCounts(Request $request, string $type, int $id): JsonResponse
    {
        try {
            $content = $this->getContent($type, $id);
            
            if (!$content) {
                return response()->json(['error' => 'Content not found'], 404);
            }

            $counts = $this->socialSharingService->getShareCounts($content);

            return response()->json($counts);
        } catch (\Exception $e) {
            Log::error('Failed to get share counts: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get share counts'], 500);
        }
    }

    /**
     * Get sharing analytics (admin only)
     */
    public function getAnalytics(Request $request): JsonResponse
    {
        $this->authorize('admin'); // Assuming you have admin middleware

        try {
            $days = $request->get('days', 30);
            $analytics = $this->socialSharingService->getSharingAnalytics($days);

            return response()->json($analytics);
        } catch (\Exception $e) {
            Log::error('Failed to get sharing analytics: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get analytics'], 500);
        }
    }

    /**
     * Get popular content for sharing suggestions
     */
    public function getPopularContent(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 5);
            $popularContent = $this->socialSharingService->getPopularContent($limit);

            return response()->json($popularContent);
        } catch (\Exception $e) {
            Log::error('Failed to get popular content: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get popular content'], 500);
        }
    }

    /**
     * Get content by type and ID
     */
    protected function getContent(string $type, int $id)
    {
        switch ($type) {
            case 'match':
                return FootballMatch::find($id);
            case 'blog':
                return Blog::find($id);
            case 'prediction':
                return PredictionSet::find($id);
            default:
                return null;
        }
    }
}