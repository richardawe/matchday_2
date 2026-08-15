<?php
namespace App\Console\Commands;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\NewsCandidate;
use App\Models\SyncRun;
use Illuminate\Console\Command;
class MatchdayHealth extends Command {
    protected $signature='matchday:health';
    protected $description='Read-only production health report for fixtures, events, news and scheduled syncs';
    public function handle():int{
        $live=FootballMatch::whereIn('status',FootballMatch::LIVE_STATUSES);
        $rows=[
            ['Fixtures today',FootballMatch::whereDate('match_date',now())->count()],
            ['Live records',(clone $live)->count()],
            ['Stale live records',(clone $live)->where(fn($q)=>$q->whereNull('last_api_update')->orWhere('last_api_update','<',now()->subMinutes(5)))->count()],
            ['Upcoming 14 days',FootballMatch::whereIn('status',['SCHEDULED','TIMED'])->whereBetween('match_date',[now(),now()->addDays(14)])->count()],
            ['Recent finals missing score',FootballMatch::whereIn('status',['FINISHED','FT'])->where('match_date','>=',now()->subDays(7))->where(fn($q)=>$q->whereNull('home_score')->orWhereNull('away_score'))->count()],
            ['Recent finals missing events',FootballMatch::whereIn('status',['FINISHED','FT'])->where('match_date','>=',now()->subDays(7))->whereDoesntHave('events')->count()],
            ['Events updated today',MatchEvent::whereDate('updated_at',now())->count()],
            ['News published today',NewsCandidate::where('status','published')->whereDate('updated_at',now())->count()],
            ['Last successful sync',SyncRun::where('status','success')->max('finished_at')??'Never'],
            ['Last failed sync',SyncRun::where('status','failed')->max('finished_at')??'None'],
        ];
        $this->table(['Check','Value'],$rows);
        return self::SUCCESS;
    }
}
