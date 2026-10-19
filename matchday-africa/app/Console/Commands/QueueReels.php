<?php
namespace App\Console\Commands;
use App\Models\FootballMatch;
use App\Services\ReelService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class QueueReels extends Command {
    protected $signature='reel:queue {--from= : First Lagos date (YYYY-MM-DD)} {--to= : Last Lagos date} {--force : Re-render reels that are already made}';
    protected $description='Queue reels for finished Premier League matches between two dates (backfill); the scheduler renders one a minute';
    public function handle(ReelService $reels): int {
        $tz=config('buffer.x.timezone');
        foreach(['from','to'] as $o){ if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$this->option($o))){ $this->error("--{$o} must be a date like 2026-10-17."); return self::INVALID; } }
        // matches.match_date holds UTC wall-clock values.
        $from=Carbon::parse($this->option('from'),$tz)->startOfDay()->setTimezone('UTC');
        $to=Carbon::parse($this->option('to'),$tz)->endOfDay()->setTimezone('UTC');
        $queued=0; $skipped=[];
        foreach(FootballMatch::with(['league','homeTeam','awayTeam'])->whereBetween('match_date',[$from->format('Y-m-d H:i:s'),$to->format('Y-m-d H:i:s')])->orderBy('match_date')->get() as $m){
            if(!$this->option('force') && data_get($m->metadata,'reel.status')==='ready'){ $skipped[]="#{$m->id} already has a reel"; continue; }
            $reels->queue($m) ? $queued++ : ($m->league?->short_code==='PL' ? $skipped[]="#{$m->id} {$m->homeTeam?->name} v {$m->awayTeam?->name}: not finished or no art" : null);
        }
        foreach($skipped as $line) $this->line("  skipped {$line}");
        $this->info("{$queued} reels queued; the scheduler renders one a minute.");
        return self::SUCCESS;
    }
}
