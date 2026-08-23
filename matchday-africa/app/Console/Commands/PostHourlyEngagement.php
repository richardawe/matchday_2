<?php

namespace App\Console\Commands;

use App\Models\Blog;
use App\Models\FootballMatch;
use App\Models\GrowthPost;
use App\Models\PredictionSet;
use App\Services\TwitterService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PostHourlyEngagement extends Command
{
    protected $signature = 'twitter:engage {--dry-run : Show the post without sending it}';
    protected $description = 'Post one fresh news, match, prediction or War engagement update to X';

    public function handle(TwitterService $twitter): int
    {
        if (GrowthPost::where('status', 'published')->where('published_at', '>=', today())->count() >= config('services.growth.x_daily_limit', 8)) {
            $this->info('Daily X post limit reached.');
            return self::SUCCESS;
        }

        $candidates = collect([
            ['type' => 'live', 'text' => $this->liveMatchPost()],
            ['type' => 'news', 'text' => $this->newsPost()],
            ['type' => 'prediction', 'text' => $this->predictionPost()],
            ['type' => 'upcoming', 'text' => $this->upcomingPost()],
            ['type' => 'war', 'text' => $this->warPost()],
        ])->filter(fn (array $item) => filled($item['text']));

        $candidate = $candidates->first(fn (array $item) => !GrowthPost::where('content_key', $this->contentKey($item['text']))
            ->whereIn('status', ['pending', 'published'])->exists());
        if (!$candidate) {
            $this->info('No fresh engagement post is available.');
            return self::SUCCESS;
        }
        $post = $candidate['text'];
        if ($this->option('dry-run')) {
            $this->line($post);
            return self::SUCCESS;
        }

        $record = GrowthPost::updateOrCreate(
            ['content_key' => $this->contentKey($post)],
            ['type' => $candidate['type'], 'message' => $post, 'status' => 'pending', 'last_error' => null]
        );
        $result = $twitter->postTweet($post);
        if (!($result['success'] ?? false)) {
            $record->update(['status' => 'failed', 'last_error' => Str::limit($result['error'] ?? 'X rejected the post.', 1000)]);
            $this->error($result['error'] ?? 'X rejected the post.');
            return self::FAILURE;
        }
        $record->update([
            'status' => 'published',
            'provider_post_id' => $result['tweet_id'] ?? null,
            'published_at' => now(),
        ]);
        $this->info('Engagement post sent: '.($result['tweet_id'] ?? 'accepted'));
        return self::SUCCESS;
    }

    private function liveMatchPost(): ?string
    {
        $match=FootballMatch::with(['homeTeam','awayTeam'])->crediblyLive()->orderByDesc('last_api_update')->first();
        if(!$match)return null;
        return "LIVE {$match->minute}' | {$match->homeTeam?->name} {$match->home_score}–{$match->away_score} {$match->awayTeam?->name}\n\nFollow every turn in the match room: ".route('matches.show',$match)."\n\n#LiveFootball #MatchdayAfrica";
    }

    private function newsPost(): ?string
    {
        $blog=Blog::published()->where('published_at','>=',now()->subDays(3))->latest('published_at')->first();
        return $blog ? Str::limit("NEW: {$blog->title}\n\n{$blog->excerpt}\n\n".route('blogs.show',$blog)."\n\n#FootballNews #MatchdayAfrica",275,'…') : null;
    }

    private function predictionPost(): ?string
    {
        $round=PredictionSet::where('status','active')->where('prediction_deadline','>',now())->withCount('matches')->orderBy('prediction_deadline')->first();
        return $round ? "MAKE THE CALL: {$round->name}\n\n{$round->matches_count} predictions. One table. Deadline {$round->prediction_deadline->format('D H:i')}.\n\n".route('predictions.show',$round)."\n\n#FootballPredictions #MatchdayAfrica" : null;
    }

    private function warPost(): string
    {
        return "Two banners. One battlefield. Choose your side, challenge a rival and enter The War.\n\n".route('war.index')."\n\n#FootballFans #TheWar #MatchdayAfrica";
    }

    private function upcomingPost(): ?string
    {
        $match=FootballMatch::with(['homeTeam','awayTeam','league'])->where('match_date','>',now())->orderBy('match_date')->first();
        return $match ? "NEXT: {$match->homeTeam?->name} v {$match->awayTeam?->name}\n{$match->league?->name} · {$match->match_date->format('D H:i')}\n\nStats, predictions and match room: ".route('matches.show',$match)."\n\n#Football #MatchdayAfrica" : null;
    }

    private function contentKey(string $text): string { return sha1($text); }
}
