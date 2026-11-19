<?php

namespace App\Http\Middleware;

use App\Models\AgentAuditLog;
use App\Models\Blog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins may open every admin page. The agent role (the social media assistant) may open only the
 * pages in AGENT_ROUTES: the Content studio, the social queue, read-only content lists, and draft
 * articles (an article it saves is always a draft, and it cannot edit a published one). Billing,
 * payments, users, secrets, uploads and every delete stay closed. The agent's requests are logged.
 * Runs before route-model binding (bootstrap/app.php), so a closed page answers 403 whatever the id.
 */
class AdminMiddleware
{
    public const AGENT_ROUTES = [
        'admin.studio.index', 'admin.studio.render', 'admin.studio.update',
        'admin.social-queue.index', 'admin.social-queue.posted',
        'admin.blogs.index', 'admin.blogs.create', 'admin.blogs.store', 'admin.blogs.show', 'admin.blogs.edit', 'admin.blogs.update', 'admin.blogs.preview',
        'admin.slideshows.index', 'admin.matches.index', 'admin.matches.show', 'admin.match-previews.index',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login')->with('error', 'Please login to access this page.');
        }
        $user = auth()->user();
        if ($user->isAdmin()) {
            return $next($request);
        }
        if (!$user->isAgent()) {
            abort(403, 'Access denied. Admin privileges required.');
        }

        $route = (string) $request->route()?->getName();
        if ($route === 'admin.index' || $route === 'admin.dashboard') {
            return redirect()->route('admin.studio.index');
        }
        if (!in_array($route, self::AGENT_ROUTES, true) || !$this->agentMayTouch($request, $route)) {
            $this->log($request, $route, 403);
            abort(403, 'The agent role cannot open this page.');
        }
        if (in_array($route, ['admin.blogs.store', 'admin.blogs.update'], true)) {
            $request->merge(['status' => 'draft']);
        }
        $started = microtime(true);
        $response = $next($request);
        if (!$request->isMethod('GET')) {
            $this->log($request, $route, $response->getStatusCode(), $started);
        }

        return $response;
    }

    /** Drafts only: the agent edits an article only while it is not published. */
    private function agentMayTouch(Request $request, string $route): bool
    {
        if (!in_array($route, ['admin.blogs.edit', 'admin.blogs.update'], true)) {
            return true;
        }
        $param = $request->route('blog');
        $blog = $param instanceof Blog ? $param : Blog::find($param);

        return $blog && $blog->status !== 'published';
    }

    private function log(Request $request, string $route, int $status, ?float $started = null): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('agent_audit_logs')) {
                AgentAuditLog::create(['user_id' => auth()->id(), 'channel' => 'web', 'method' => $request->method(),
                    'path' => substr('/'.ltrim($request->path(), '/'), 0, 255), 'route' => $route ?: null, 'status' => $status,
                    'ip' => $request->ip(), 'params' => ['fields' => array_values(array_diff(array_keys($request->except(['_token', 'password'])), ['_method']))],
                    'duration_ms' => $started ? (int) ((microtime(true) - $started) * 1000) : null, 'created_at' => now()]);
            }
        } catch (\Throwable) {
            // The audit log never blocks the page.
        }
    }
}
