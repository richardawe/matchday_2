<?php
namespace App\Console\Commands;
use App\Services\BufferPublisher;
use App\Support\BufferUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PublishToBuffer extends Command {
    protected $signature='buffer:publish {--channels : List the Buffer channel IDs for BUFFER_CHANNEL_*}';
    protected $description='Send new Griot cards, full-time reels and the planned X timeline to Buffer';
    public function handle(BufferPublisher $buffer): int {
        if(!config('buffer.key')){ $this->warn('BUFFER_API_KEY is not set; nothing sent.'); return self::SUCCESS; }
        if($this->option('channels')){
            try { $channels=$buffer->channels(); } catch(\Throwable $e){ $this->error('Buffer: '.$e->getMessage()); return self::FAILURE; }
            $this->table(['ID','Name','Service','Health'],collect($channels)->map(fn($c)=>[$c['id'],$c['name']??'',$c['service']??'',
                collect($c)->except(['id','name','service'])->map(fn($v,$k)=>$k.'='.json_encode($v))->implode(' ')])->all());
            $usage=BufferUsage::summary();
            $this->line("API requests this month: {$usage['used']} of {$usage['limit']}");
            return self::SUCCESS;
        }
        if(!$buffer->configured()||!Schema::hasTable('social_posts')){ $this->warn('No BUFFER_CHANNEL_* set, or migrations not run; nothing sent.'); return self::SUCCESS; }
        // One sender at a time (the scheduler and a manual run): two would both see a slot as free.
        $lock=Cache::lock('buffer:publish',600);
        if(!$lock->get()){ $this->warn('Another buffer:publish is running; nothing sent.'); return self::SUCCESS; }
        try { [$sent,$failed]=$buffer->publishPending(); } finally { $lock->release(); }
        $this->info("Buffer: {$sent} sent, {$failed} failed");
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
