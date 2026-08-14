<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\FootballMatch;
use App\Services\MatchPreviewService;
use Illuminate\Support\Facades\Log;

class GenerateMatchPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $backoff = [60, 300, 600]; // Retry delays: 1min, 5min, 10min

    protected $matchId;

    /**
     * Create a new job instance.
     */
    public function __construct($matchId)
    {
        $this->matchId = $matchId;
        $this->onQueue('previews');
    }

    /**
     * Execute the job.
     */
    public function handle(MatchPreviewService $previewService)
    {
        try {
            $match = FootballMatch::find($this->matchId);
            
            if (!$match) {
                Log::warning('Match not found for preview generation', ['match_id' => $this->matchId]);
                return;
            }

            Log::info('Starting preview generation job', [
                'match_id' => $this->matchId,
                'job_id' => $this->job->getJobId()
            ]);

            $preview = $previewService->generatePreview($match);
            
            if ($preview) {
                Log::info('Preview generation job completed successfully', [
                    'match_id' => $this->matchId,
                    'preview_id' => $preview->id
                ]);
            } else {
                Log::error('Preview generation job failed', ['match_id' => $this->matchId]);
                throw new \Exception('Failed to generate preview');
            }

        } catch (\Exception $e) {
            Log::error('Preview generation job failed with exception', [
                'match_id' => $this->matchId,
                'error' => $e->getMessage(),
                'job_id' => $this->job->getJobId()
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Preview generation job failed permanently', [
            'match_id' => $this->matchId,
            'error' => $exception->getMessage(),
            'job_id' => $this->job->getJobId()
        ]);
    }
} 