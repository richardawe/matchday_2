<?php

namespace App\Http\Middleware;

use App\Models\AgentAuditLog;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/agent: a bearer token from api_tokens (SHA-256 hash lookup) belonging to an agent or an
 * admin. Sets the request's user, and writes one audit row per request (method, path, status,
 * query keys and the ids/filters it used; never the token or body text).
 */
class AgentApiToken
{
    private const LOGGED_VALUES = ['status', 'from', 'to', 'competition', 'country', 'club', 'african', 'latest', 'days', 'type',
        'match_id', 'episode_id', 'lang', 'channel', 'limit', 'page', 'id'];

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $request->headers->set('Accept', 'application/json'); // validation errors come back as JSON, never a redirect
        $token = ApiToken::ready() ? ApiToken::findLive($request->bearerToken()) : null;
        $user = $token?->user;
        if (!$token || !$user || (!$user->isAgent() && !$user->isAdmin())) {
            $this->log($request, null, null, 401, $started);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);
        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $response = $next($request);
        $this->log($request, $user->id, $token->id, $response->getStatusCode(), $started);

        return $response;
    }

    private function log(Request $request, ?int $userId, ?int $tokenId, int $status, float $started): void
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('agent_audit_logs')) {
                return;
            }
            $values = collect($request->query())->merge($request->isJson() ? $request->json()->all() : $request->post())
                ->only(self::LOGGED_VALUES)->map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 60) : null)->filter(fn ($v) => $v !== null);
            AgentAuditLog::create(['user_id' => $userId, 'api_token_id' => $tokenId, 'channel' => 'api', 'method' => $request->method(),
                'path' => substr('/'.ltrim($request->path(), '/'), 0, 255), 'route' => $request->route()?->getName(), 'status' => $status,
                'ip' => $request->ip(), 'params' => ['values' => $values->all(), 'fields' => array_keys($request->except(['password']))],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'created_at' => now()]);
        } catch (\Throwable) {
            // The audit log never blocks the API.
        }
    }
}
