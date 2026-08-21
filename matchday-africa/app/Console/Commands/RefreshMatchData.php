<?php
namespace App\Console\Commands;
use App\Models\FootballMatch;
use App\Services\MatchService;
use App\Services\PredictionScoringService;
use App\Services\SyncRunService;
use Illuminate\Console\Command;

class RefreshMatchData extends Command {
    protected $signature='matches:refresh {mode : upcoming|today|live|results}';
    protected $description='Refresh match data with an adaptive matchday cadence';
    public function handle(MatchService $matches, SyncRunService $runs, PredictionScoringService $scoring): int {
        $mode=$this->argument('mode');
        if(!in_array($mode,['upcoming','today','live','results'],true)){ $this->error('Invalid refresh mode.'); return self::INVALID; }
        $result=$runs->run('matches:'.$mode,function()use($mode,$matches,$scoring){
            // football-data.org rejects date ranges longer than 10 days.
            if($mode==='upcoming') return $matches->syncUpcomingMatches(10);
            if($mode==='live') return $matches->syncLiveMatches();
            $result=$matches->syncTodaysMatches();
            if($mode==='results'){
                $events=$matches->syncEventsForFinishedMatches(20);
                $scores=$scoring->scoreAllPendingPredictions();
                $result['context']=['events'=>$events,'predictions'=>$scores];
            }
            return $result;
        });
        $this->info($result['message']??'Refresh complete');
        return ($result['errors']??0)>0?self::FAILURE:self::SUCCESS;
    }
}
