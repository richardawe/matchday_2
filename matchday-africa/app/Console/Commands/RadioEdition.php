<?php

namespace App\Console\Commands;

use App\Models\RadioBulletin;
use App\Services\RadioService;
use Illuminate\Console\Command;

class RadioEdition extends Command
{
    protected $signature = 'radio:edition';

    protected $description = 'Queue the current 06:00, 12:00 or 18:00 Lagos bulletin once';

    public function handle(RadioService $radio): int
    {
        if (! RadioBulletin::available()) {
            $this->info('Radio is off or awaiting its migration.');

            return self::SUCCESS;
        }
        $this->info('Edition '.$radio->queue()->slot.' is queued or already exists.');

        return self::SUCCESS;
    }
}
