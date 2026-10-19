<?php
namespace App\Console\Commands;
use App\Services\GrowthPostGenerator;
use Illuminate\Console\Command;
class PostHourlyEngagement extends Command {
    protected $signature='twitter:engage {--dry-run : Report without saving}';
    protected $description='Plan the social timeline: news, fixture previews and the daily round-ups (queued for Buffer when BUFFER_AUTO is on)';
    public function handle(GrowthPostGenerator $generator): int {
        if($this->option('dry-run')){$this->info('Draft generation is ready.');return self::SUCCESS;}
        $this->info($generator->generate().' posts planned.');return self::SUCCESS;
    }
}
