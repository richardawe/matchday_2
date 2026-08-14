<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TwitterService;

class TestOAuth2Flow extends Command
{
    protected $signature = 'twitter:test-oauth2 {--tweet : Test posting a tweet}';
    protected $description = 'Test OAuth 2.0 with PKCE flow for Twitter';

    public function handle()
    {
        $twitterService = app(TwitterService::class);

        $this->info('🧪 Testing OAuth 2.0 with PKCE Flow');
        $this->line('');

        // Test 1: Generate authorization URL
        $this->info('1. Generating OAuth 2.0 authorization URL...');
        $authResult = $twitterService->getAuthorizationUrl();
        
        if ($authResult['success']) {
            $this->info('✅ Authorization URL generated successfully');
            $this->line('   URL: ' . $authResult['auth_url']);
            $this->line('   State: ' . $authResult['state']);
        } else {
            $this->error('❌ Failed to generate authorization URL: ' . $authResult['error']);
            return 1;
        }

        $this->line('');

        // Test 2: Test posting with simulated access token
        if ($this->option('tweet')) {
            $this->info('2. Testing tweet posting with simulated access token...');
            
            // Simulate having an access token in session
            session(['twitter_access_token' => 'simulated_access_token_for_testing']);
            
            $tweetResult = $twitterService->postTweet('🧪 Test tweet from @matchdayafrica - OAuth 2.0 with PKCE - ' . now() . ' #TestTweet #MatchdayAfrica');
            
            if ($tweetResult['success']) {
                $this->info('✅ Tweet posted successfully');
                $this->line('   Tweet ID: ' . ($tweetResult['tweet_id'] ?? 'N/A'));
            } else {
                $this->warn('⚠️  Tweet posting failed (expected with simulated token): ' . $tweetResult['error']);
                $this->line('   This is expected since we used a simulated token');
            }
        }

        $this->line('');
        $this->info('🎯 OAuth 2.0 Flow Test Complete!');
        $this->line('');
        $this->info('📋 Next Steps:');
        $this->line('1. Go to /admin/twitter (as admin)');
        $this->line('2. Click "🔐 Authorize Twitter"');
        $this->line('3. Complete the Twitter authorization');
        $this->line('4. Test posting tweets through the admin interface');

        return 0;
    }
}
