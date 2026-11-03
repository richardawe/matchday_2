<?php
namespace App\Console\Commands;
use App\Models\FootballMatch;
use App\Services\ReelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RenderPendingReels extends Command {
    protected $signature='reel:render {match? : Match ID to (re)render now}';
    protected $description='Make the Griot\'s 20-second reel for finished Premier League matches';
    public function handle(ReelService $reels): int {
        if(config('reel.renderer')==='off'){ $this->warn('REEL_RENDERER is off; nothing rendered.'); return self::SUCCESS; }
        if(!Schema::hasTable('match_moments')){ $this->warn('match_moments table missing; run migrations.'); return self::SUCCESS; }
        if($id=$this->argument('match')){ $this->info($reels->make(FootballMatch::findOrFail($id))); return self::SUCCESS; }
        if($found=$reels->collectUploads()) $this->info("{$found} reels arrived from GitHub");
        if($again=$reels->requeueCorrected()) $this->info("{$again} reels queued again after a correction");
        $pending=$reels->pending()->values();
        if($pending->isEmpty()){ $this->info('No reels waiting.'); return self::SUCCESS; }
        // GitHub renders run side by side: send every waiting match at once.
        if(config('reel.renderer')==='github'){
            foreach($pending as $match) $this->info("Match {$match->id}: ".$reels->make($match));
            return self::SUCCESS;
        }
        // This server renders one at a time (a few minutes of CPU each). When matches finish
        // together, the others go to GitHub first so they don't queue behind this one.
        if(config('reel.overflow')==='github' && config('reel.github.token')){
            foreach($pending->slice(1) as $match) $this->info("Match {$match->id}: ".$reels->make($match, 'github'));
        }
        $match=$pending->first();
        $this->info("Match {$match->id}: ".$reels->make($match));
        return self::SUCCESS;
    }
}
