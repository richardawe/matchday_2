<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FootballMatch;
use App\Services\TwitterService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TwitterController extends Controller
{
    private TwitterService $twitterService;

    public function __construct(TwitterService $twitterService)
    {
        $this->twitterService = $twitterService;
    }

    /**
     * Display Twitter management dashboard
     */
    public function index()
    {
        // Get today's matches
        $todayMatches = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->whereDate('match_date', today())
            ->where('status', '!=', 'FINISHED')
            ->orderBy('match_date')
            ->get();

        // Get tomorrow's matches
        $tomorrowMatches = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->whereDate('match_date', today()->addDay())
            ->where('status', '!=', 'FINISHED')
            ->orderBy('match_date')
            ->get();

        // Test Twitter connection
        $connectionTest = $this->twitterService->testConnection();

        return view('admin.twitter.index', compact(
            'todayMatches',
            'tomorrowMatches',
            'connectionTest'
        ));
    }

    /**
     * Tweet matches for a specific date
     */
    public function tweetMatches(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
            'type' => 'required|in:individual,summary,both',
            'delay' => 'integer|min:10|max:300'
        ]);

        $date = $request->input('date');
        $type = $request->input('type');
        $delay = $request->input('delay', 30);

        try {
            // Build command arguments
            $command = "twitter:tweet-matches {$date}";
            
            if ($type === 'individual' || $type === 'both') {
                $command .= ' --individual';
            }
            if ($type === 'summary' || $type === 'both') {
                $command .= ' --summary';
            }
            
            $command .= " --delay={$delay}";

            // Execute the command
            $exitCode = Artisan::call($command);
            $output = Artisan::output();

            if ($exitCode === 0) {
                Log::info('Admin triggered Twitter tweets', [
                    'date' => $date,
                    'type' => $type,
                    'delay' => $delay,
                    'output' => $output
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Tweets posted successfully!',
                    'output' => $output
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to post tweets',
                    'output' => $output
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Admin Twitter tweet failed', [
                'date' => $date,
                'type' => $type,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test Twitter API connection
     */
    public function testConnection(): JsonResponse
    {
        try {
            $result = $this->twitterService->testConnection();
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection test failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a test tweet
     */
    public function sendTestTweet(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:280'
        ]);

        try {
            $tweetText = $request->input('text');
            $result = $this->twitterService->postTweet($tweetText);

            if ($result['success']) {
                Log::info('Admin sent test tweet', [
                    'tweet_id' => $result['tweet_id'],
                    'text' => $tweetText
                ]);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Admin test tweet failed', [
                'text' => $request->input('text'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Test tweet failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Initiate Twitter OAuth 2.0 authorization
     */
    public function authorize(): JsonResponse
    {
        try {
            $result = $this->twitterService->getAuthorizationUrl();
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get authorization URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
