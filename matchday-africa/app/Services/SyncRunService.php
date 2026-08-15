<?php
namespace App\Services;
use App\Models\SyncRun;
use Throwable;
class SyncRunService {
    public function run(string $task, callable $callback): array {
        $run=SyncRun::create(['task'=>$task,'status'=>'running','started_at'=>now()]);
        try {
            $result=(array)$callback();
            $errors=(int)($result['errors']??0);
            $run->update(['status'=>$errors?'warning':'success','finished_at'=>now(),'records_processed'=>(int)($result['success']??$result['processed']??0),'errors'=>$errors,'message'=>$result['message']??null,'context'=>$result]);
            return $result;
        } catch(Throwable $e) {
            $run->update(['status'=>'failed','finished_at'=>now(),'errors'=>1,'message'=>$e->getMessage()]);
            throw $e;
        }
    }
}
