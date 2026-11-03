<?php
namespace App\Console\Commands;

use App\Services\Alerts\WebPush;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PushKeys extends Command
{
    protected $signature = 'push:keys {--write : Save new keys to .env (through scripts/set-env.php, which backs it up) unless keys exist} {--force : Replace existing keys; every browser must subscribe again}';
    protected $description = 'Create the VAPID key pair web push needs';

    public function handle(): int
    {
        if (config('push.vapid.public_key') && !$this->option('force')) {
            $this->info('VAPID keys already set. Public key: '.config('push.vapid.public_key'));

            return self::SUCCESS;
        }
        $keys = WebPush::createKeys();
        if (!$this->option('write')) {
            $this->line("PUSH_VAPID_PUBLIC_KEY={$keys['public_key']}");
            $this->line("PUSH_VAPID_PRIVATE_KEY={$keys['private_key']}");

            return self::SUCCESS;
        }
        $process = new Process([PHP_BINARY, base_path('scripts/set-env.php')], base_path());
        $process->setInput(json_encode(['PUSH_VAPID_PUBLIC_KEY' => $keys['public_key'], 'PUSH_VAPID_PRIVATE_KEY' => $keys['private_key']]));
        $process->run();
        if (!$process->isSuccessful()) {
            $this->error('Could not write .env: '.$process->getErrorOutput());

            return self::FAILURE;
        }
        $this->info('VAPID keys written to .env. Public key: '.$keys['public_key']);

        return self::SUCCESS;
    }
}
