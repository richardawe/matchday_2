<?php

namespace App\Console\Commands;

use App\Services\RadioService;
use Illuminate\Console\Command;

class RadioWork extends Command
{
    protected $signature = 'radio:work';

    protected $description = 'Write or voice one queued bulletin, with bounded retries';

    public function handle(RadioService $radio): int
    {
        $message = $radio->work();
        $this->info($message);

        return str_contains($message, ' failed:') ? self::FAILURE : self::SUCCESS;
    }
}
