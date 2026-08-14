<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\FootballMatch;
use App\Models\MatchChat;
use App\Services\GiphyService;

class ChatController extends Controller
{
    public function __construct(
        private GiphyService $giphyService
    ) {
        // Middleware will be handled in routes instead of constructor
    }

    /**
     * Get chat messages for a match
     */
    public function getMessages(FootballMatch $match): JsonResponse
    {
        $chats = $match->chats()
            ->with('user:id,name')
            ->orderBy('created_at', 'asc')
            ->limit(100)
            ->get()
            ->map(function ($chat) {
                return [
                    'id' => $chat->id,
                    'message' => $chat->message,
                    'processed_message' => $chat->processed_message,
                    'gif_url' => $chat->gif_url,
                    'gif_title' => $chat->gif_title,
                    'is_gif' => $chat->is_gif,
                    'mentioned_users' => $chat->mentioned_users,
                    'user' => [
                        'id' => $chat->user->id,
                        'name' => $chat->user->name,
                    ],
                    'created_at' => $chat->created_at->format('H:i'),
                    'time_ago' => $chat->created_at->diffForHumans(),
                ];
            });

        return response()->json(['chats' => $chats]);
    }

    /**
     * Store a new chat message
     */
    public function storeMessage(Request $request, FootballMatch $match): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required_without:gif_url|string|max:500',
            'gif_url' => 'required_without:message|url|max:500',
            'gif_title' => 'nullable|string|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $isGif = !empty($request->gif_url);
        $mentionedUsers = [];
        
        // Extract mentions from message
        if (!$isGif && $request->message) {
            $mentionedUsernames = MatchChat::extractMentions($request->message);
            if (!empty($mentionedUsernames)) {
                // Validate that mentioned users exist
                $existingUsers = \App\Models\User::whereIn('name', $mentionedUsernames)->pluck('name')->toArray();
                $mentionedUsers = $existingUsers;
            }
        }
        
        $chat = MatchChat::create([
            'match_id' => $match->id,
            'user_id' => Auth::id(),
            'message' => $isGif ? null : $request->message,
            'gif_url' => $isGif ? $request->gif_url : null,
            'gif_title' => $isGif ? $request->gif_title : null,
            'is_gif' => $isGif,
            'mentioned_users' => !empty($mentionedUsers) ? $mentionedUsers : null,
        ]);

        $chat->load('user:id,name');

        $chatData = [
            'id' => $chat->id,
            'message' => $chat->message,
            'processed_message' => $chat->processed_message,
            'gif_url' => $chat->gif_url,
            'gif_title' => $chat->gif_title,
            'is_gif' => $chat->is_gif,
            'mentioned_users' => $chat->mentioned_users,
            'user' => [
                'id' => $chat->user->id,
                'name' => $chat->user->name,
            ],
            'created_at' => $chat->created_at->format('H:i'),
            'time_ago' => $chat->created_at->diffForHumans(),
        ];

        return response()->json([
            'success' => true,
            'chat' => $chatData
        ]);
    }

    /**
     * Search for GIFs
     */
    public function searchGifs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:100',
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $query = $request->input('query');
        $offset = $request->input('offset', 0);

        $result = $this->giphyService->search($query, $offset);

        return response()->json([
            'success' => true,
            'gifs' => $result['gifs'],
            'total' => $result['total'],
            'has_more' => count($result['gifs']) === config('services.giphy.limit', 20)
        ]);
    }

    /**
     * Get trending GIFs
     */
    public function getTrendingGifs(Request $request): JsonResponse
    {
        $offset = $request->input('offset', 0);
        $result = $this->giphyService->trending($offset);

        return response()->json([
            'success' => true,
            'gifs' => $result['gifs'],
            'total' => $result['total'],
            'has_more' => count($result['gifs']) === config('services.giphy.limit', 20)
        ]);
    }

    /**
     * Get football-related GIFs
     */
    public function getFootballGifs(): JsonResponse
    {
        $result = $this->giphyService->getFootballGifs();

        return response()->json([
            'success' => true,
            'gifs' => $result['gifs'],
            'total' => $result['total'],
        ]);
    }

    /**
     * Search for users to mention
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $query = $request->get('query', '');
        
        if (strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'users' => []
            ]);
        }

        $users = \App\Models\User::where('name', 'LIKE', '%' . $query . '%')
            ->select('id', 'name')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'users' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => substr($user->name, 0, 1)
                ];
            })
        ]);
    }
}
