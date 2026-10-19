<?php
namespace App\Console\Commands;
use App\Services\BufferPublisher;
use App\Support\BufferUsage;
use Illuminate\Console\Command;
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
        [$sent,$failed]=$buffer->publishPending();
        $this->info("Buffer: {$sent} sent, {$failed} failed");
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
