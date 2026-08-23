<?php
namespace App\Console\Commands;
use App\Services\GrowthPostGenerator;
use Illuminate\Console\Command;
class PostHourlyEngagement extends Command {
    protected $signature='twitter:engage {--dry-run : Report without saving}';
    protected $description='Generate fresh X drafts for admin review';
    public function handle(GrowthPostGenerator $generator): int {
        if($this->option('dry-run')){$this->info('Draft generation is ready.');return self::SUCCESS;}
        $this->info($generator->generate().' new X drafts generated.');return self::SUCCESS;
    }
}
