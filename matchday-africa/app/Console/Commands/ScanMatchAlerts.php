<?php
namespace App\Console\Commands;

use App\Services\Alerts\MatchAlerts;
use Illuminate\Console\Command;

class ScanMatchAlerts extends Command
{
    protected $signature = 'alerts:scan';
    protected $description = 'Send match alerts (kick-off, goals, red cards, half-time, full time, reel) to users who asked for them';

    public function handle(MatchAlerts $alerts): int
    {
        $this->info($alerts->scan().' alerts sent');

        return self::SUCCESS;
    }
}
