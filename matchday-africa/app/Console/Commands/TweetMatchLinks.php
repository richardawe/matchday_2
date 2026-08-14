<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\TwitterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TweetMatchLinks extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'twitter:tweet-matches 
                            {date? : The date to tweet matches for (Y-m-d format, defaults to today)}
                            {--individual : Tweet each match individually}
                            {--summary : Tweet a summary of all matches}
                            {--delay=30 : Delay between individual tweets in seconds}';

    /**
     * The console command description.
     */
    protected $description = 'Tweet match links for matches on a specific date';

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
        $date = $this->argument('date') ?: now()->format('Y-m-d');
        $individual = $this->option('individual');
        $summary = $this->option('summary');
        $delay = (int) $this->option('delay');

        // If no specific option is chosen, default to both
        if (!$individual && !$summary) {
            $individual = true;
            $summary = true;
        }

        $this->info("🐦 Tweeting match links for {$date}");

        // Test Twitter API connection first
        $connectionTest = $this->twitterService->testConnection();
        if (!$connectionTest['success']) {
            $this->error("❌ Twitter API connection failed: " . $connectionTest['error']);
            return 1;
        }

        $this->info("✅ Twitter API connection successful");

        // Get matches for the date
        $matches = FootballMatch::with(['homeTeam', 'awayTeam'])
            ->whereDate('match_date', $date)
            ->where('status', '!=', 'FINISHED')
            ->orderBy('match_date')
            ->get();

        if ($matches->isEmpty()) {
            $this->warn("⚠️  No matches found for {$date}");
            return 0;
        }

        $this->info("📊 Found {$matches->count()} matches for {$date}");

        $results = [];

        // Tweet individual matches if requested
        if ($individual) {
            $this->info("📝 Tweeting individual matches...");
            $individualTweets = [];
            
            foreach ($matches as $match) {
                $matchUrl = route('matches.show', $match->id);
                $tweetText = $this->twitterService->formatMatchTweet([
                    'home_team' => $match->homeTeam->name ?? 'TBD',
                    'away_team' => $match->awayTeam->name ?? 'TBD',
                    'competition' => $match->competition ?? 'Premier League',
                    'kickoff' => $match->match_date
                ], $matchUrl);
                
                $individualTweets[] = $tweetText;
            }

            $individualResults = $this->twitterService->postMultipleTweets($individualTweets, $delay);
            $results['individual'] = $individualResults;
            
            $successCount = collect($individualResults)->where('success', true)->count();
            $this->info("✅ Posted {$successCount}/" . count($individualTweets) . " individual tweets");
        }

        // Tweet summary if requested
        if ($summary) {
            $this->info("📝 Tweeting daily summary...");
            $summaryUrl = route('matches.index', ['date' => $date]);
            $summaryTweet = $this->twitterService->formatDailyMatchesTweet(
                $matches->map(function ($match) {
                    return [
                        'home_team' => $match->homeTeam->name ?? 'TBD',
                        'away_team' => $match->awayTeam->name ?? 'TBD',
                        'kickoff' => $match->match_date
                    ];
                })->toArray(),
                $date
            );

            $summaryResult = $this->twitterService->postTweet($summaryTweet);
            $results['summary'] = $summaryResult;
            
            if ($summaryResult['success']) {
                $this->info("✅ Posted daily summary tweet");
            } else {
                $this->error("❌ Failed to post summary tweet: " . $summaryResult['error']);
            }
        }

        // Display results summary
        $this->displayResults($results);

        return 0;
    }

    private function displayResults(array $results)
    {
        $this->info("\n📊 Tweet Results Summary:");
        $this->info("=========================");

        if (isset($results['individual'])) {
            $this->info("Individual Tweets:");
            foreach ($results['individual'] as $index => $result) {
                $status = $result['success'] ? '✅' : '❌';
                $tweetId = $result['tweet_id'] ?? 'N/A';
                $this->line("  {$status} Tweet " . ($index + 1) . " - ID: {$tweetId}");
            }
        }

        if (isset($results['summary'])) {
            $this->info("\nSummary Tweet:");
            $result = $results['summary'];
            $status = $result['success'] ? '✅' : '❌';
            $tweetId = $result['tweet_id'] ?? 'N/A';
            $this->line("  {$status} Summary - ID: {$tweetId}");
        }

        $this->info("\n🔗 View tweets at: https://twitter.com/matchdayafrica");
    }
}
