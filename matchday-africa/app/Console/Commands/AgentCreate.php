<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Services\Agent\AgentAccess;
use Illuminate\Console\Command;

/**
 * Creates (or updates) the "Matchday agent" login with the agent role and prints a new API token
 * once. Run it in a private terminal (cPanel Terminal or SSH), never in a CI log.
 */
class AgentCreate extends Command
{
    protected $signature = 'agent:create {email : The agent\'s login email}
        {--name=Matchday agent : Display name}
        {--password= : Set this password (otherwise a new account gets a random one, and an existing one keeps its own)}
        {--no-token : Do not issue an API token}';

    protected $description = 'Create the agent login (admin role "agent") and a long-lived API token, shown once';

    public function handle(AgentAccess $access): int
    {
        if (!ApiToken::ready()) {
            $this->error('Run the migrations first (php artisan migrate --force).');

            return self::FAILURE;
        }
        try {
            [$user, $password] = $access->upsert((string) $this->argument('email'), (string) $this->option('name'), $this->option('password') ?: null);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Agent account: {$user->name} <{$user->email}> (id {$user->id}), role agent.");
        if ($password !== null && !$this->option('password')) {
            $this->line("Password (shown once): {$password}");
        }
        if (!$this->option('no-token')) {
            [, $plain] = $access->issueToken($user);
            $this->line("API token (shown once; older tokens for this agent are revoked): {$plain}");
        }
        $this->line('Log in at /login; the agent lands on /admin/studio. API docs: docs/agent-api.md.');

        return self::SUCCESS;
    }
}
