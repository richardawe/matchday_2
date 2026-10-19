<?php
namespace App\Console\Commands;
use App\Services\Espn\EspnSyncService;
use App\Services\SyncRunService;
use Illuminate\Console\Command;

class EspnSync extends Command {
    protected $signature='espn:sync {mode : fixtures|live|details}';
    protected $description='Sync fixtures, live scores and match details from the ESPN scoreboard feed';
    public function handle(EspnSyncService $espn, SyncRunService $runs): int {
        $mode=$this->argument('mode');
        if(!in_array($mode,['fixtures','live','details'],true)){ $this->error('Invalid sync mode.'); return self::INVALID; }
        if(!config('espn.enabled')){ $this->warn('ESPN_ENABLED is false; sync skipped.'); return self::SUCCESS; }
        $sync=fn()=>match($mode){'fixtures'=>$espn->syncFixtures(),'live'=>$espn->syncLive(),'details'=>$espn->syncDetails()};
        if($mode==='fixtures') return $this->report($runs->run('espn:fixtures',$sync));
        if($mode==='details'){ $result=$sync(); return $result['requests']===0?self::SUCCESS:$this->report($runs->run('espn:details',fn()=>$result)); }

        // cPanel cron fires once a minute, so live mode polls several times inside one run and
        // only logs a sync run when there was something live to poll.
        $passes=max(1,(int)config('espn.live_passes',3));
        $total=['success'=>0,'errors'=>0,'requests'=>0,'differences'=>0,'unmatched'=>[],'passes'=>0];
        for($pass=1;$pass<=$passes;$pass++){
            $result=$sync();
            if($result['requests']===0) break;
            $total['passes']++;
            foreach(['success','errors','requests','differences'] as $key) $total[$key]+=$result[$key];
            $total['unmatched']=array_values(array_unique(array_merge($total['unmatched'],$result['unmatched'])));
            $total['paused_until']=$result['paused_until'];
            if($pass<$passes) sleep(intdiv(60,$passes));
        }
        if($total['requests']===0){ $this->info('ESPN live: nothing in the live window.'); return self::SUCCESS; }
        $total['message']=(config('espn.shadow')?"[shadow] ESPN live: {$total['passes']} passes, {$total['success']} matches compared, {$total['differences']} differ from ours":"ESPN live: {$total['passes']} passes, {$total['success']} match updates").", {$total['requests']} requests".(!empty($total['paused_until'])?' (feed paused)':'');
        return $this->report($runs->run('espn:live',fn()=>$total));
    }
    private function report(array $result): int {
        $this->info($result['message']);
        foreach($result['unmatched'] as $line) $this->line('  unmatched: '.$line);
        return $result['errors']>0?self::FAILURE:self::SUCCESS;
    }
}
