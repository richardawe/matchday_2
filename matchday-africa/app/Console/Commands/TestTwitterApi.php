<?php

namespace App\Console\Commands;

use App\Services\TwitterService;
use Illuminate\Console\Command;

class TestTwitterApi extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'twitter:test 
                            {--tweet : Send a test tweet}
                            {--text= : Custom tweet text}';

    /**
     * The console command description.
     */
    protected $description = 'Test Twitter API connection and optionally send a test tweet';

    private TwitterService $twitterService;

    public function __construct(TwitterService $twitterService)
    {
        parent::__construct();
        $this->twitterService = $twitterService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("🐦 Testing Twitter API connection...");

        // Test connection
        $connectionTest = $this->twitterService->testConnection();
        
        if (!$connectionTest['success']) {
            $this->error("❌ Twitter API connection failed:");
            $this->error($connectionTest['error']);
            return 1;
        }

        $this->info("✅ Twitter API connection successful!");
        $this->info("👤 Username: @{$connectionTest['username']}");

        if (isset($connectionTest['user_data']['data'])) {
            $userData = $connectionTest['user_data']['data'];
            $this->info("📊 User ID: {$userData['id']}");
            $this->info("📝 Name: {$userData['name']}");
            $this->info("📧 Username: @{$userData['username']}");
        }

        // Send test tweet if requested
        if ($this->option('tweet')) {
            $this->info("\n📝 Sending test tweet...");
            
            $tweetText = $this->option('text') ?: 
                "🧪 Test tweet from @matchdayafrica - " . now()->format('Y-m-d H:i:s') . " #TestTweet #MatchdayAfrica";
            
            $result = $this->twitterService->postTweet($tweetText);
            
            if ($result['success']) {
                $this->info("✅ Test tweet posted successfully!");
                $this->info("🔗 Tweet ID: {$result['tweet_id']}");
                $this->info("🔗 View at: https://twitter.com/matchdayafrica/status/{$result['tweet_id']}");
            } else {
                $this->error("❌ Failed to post test tweet:");
                $this->error($result['error']);
                return 1;
            }
        }

        $this->info("\n🎉 Twitter API test completed successfully!");
        return 0;
    }
}
