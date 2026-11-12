<?php

namespace App\Console\Commands;

use App\Services\Slideshow\Slideshows;
use Illuminate\Console\Command;

/**
 * Puts the slideshow music bed in storage/app/music (private): downloads it from the credit record's
 * Free Music Archive link when it is missing and checks it against the recorded sha256. Safe to run
 * on every deploy; does nothing when the right file is already there.
 */
class SlideshowMusic extends Command
{
    protected $signature = 'slideshow:music';

    protected $description = 'Fetch and verify the licensed slideshow music (CC BY 4.0) into storage/app/music';

    public function handle(): int
    {
        [$ok, $message] = Slideshows::fetchMusic();
        $ok ? $this->info($message) : $this->error($message);
        $this->line(Slideshows::musicCredit(Slideshows::music(), full: true));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
