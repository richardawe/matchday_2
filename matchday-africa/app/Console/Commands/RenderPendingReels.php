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
        // One at a time: a server render takes a few minutes of CPU.
        $match=$reels->pending()->first();
        if(!$match){ $this->info('No reels waiting.'); return self::SUCCESS; }
        $this->info("Match {$match->id}: ".$reels->make($match));
        return self::SUCCESS;
    }
}
