<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentAuditLog;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Agent\AgentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * /admin/agent (admins only): the agent login, its API tokens (each shown once) and the audit log.
 */
class AgentAccessController extends Controller
{
    public function __construct(private AgentAccess $access) {}

    public function index()
    {
        $ready = ApiToken::ready();

        return view('admin.agent.index', [
            'ready' => $ready,
            'agents' => User::where('role', User::ROLE_AGENT)->orderBy('id')->get(),
            'tokens' => $ready ? ApiToken::with('user')->latest()->limit(20)->get() : collect(),
            'log' => $ready ? AgentAuditLog::latest('id')->limit(60)->get() : collect(),
            'plain' => session('agent_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191'],
            'password' => ['nullable', Password::min(12)],
        ]);
        try {
            [$user] = $this->access->upsert($data['email'], $data['name'], $data['password'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Agent login saved for {$user->email}.".(empty($data['password']) && $user->wasRecentlyCreated
            ? ' It has a random password: set one here (or use Forgot password) before logging in.' : ''));
    }

    public function token(Request $request, User $user): RedirectResponse
    {
        if (!$user->isAgent()) {
            return back()->with('error', 'Tokens are only issued to agent accounts.');
        }
        [, $plain] = $this->access->issueToken($user, $request->user()->id, (bool) $request->boolean('revoke_others', true));

        // Flashed once and never stored in plain text.
        return back()->with('agent_token', $plain)->with('success', 'New API token issued. Copy it now: it is not shown again.');
    }

    public function revoke(ApiToken $token): RedirectResponse
    {
        $token->update(['revoked_at' => now()]);

        return back()->with('success', "Token {$token->prefix}… revoked.");
    }
}
