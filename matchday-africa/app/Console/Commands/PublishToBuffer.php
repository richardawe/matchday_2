<?php
namespace App\Console\Commands;
use App\Services\BufferPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PublishToBuffer extends Command {
    protected $signature='buffer:publish {--channels : List the Buffer channel IDs for BUFFER_CHANNEL_*}';
    protected $description='Send new Griot cards and full-time reels to Buffer (X, Instagram, TikTok)';
    public function handle(BufferPublisher $buffer): int {
        if(!config('buffer.key')){ $this->warn('BUFFER_API_KEY is not set; nothing sent.'); return self::SUCCESS; }
        if($this->option('channels')){
            $this->table(['ID','Name','Service'],collect($buffer->channels())->map(fn($c)=>[$c['id'],$c['name'],$c['service']])->all());
            return self::SUCCESS;
        }
        if(!$buffer->configured()||!Schema::hasTable('social_posts')){ $this->warn('No BUFFER_CHANNEL_* set, or migrations not run; nothing sent.'); return self::SUCCESS; }
        [$sent,$failed]=$buffer->publishPending();
        $this->info("Buffer: {$sent} sent, {$failed} failed");
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
