<?php

// CLI-only radio desk for the production Server operations workflow.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$action = $argv[1] ?? 'status';
$id = $argv[2] ?? null;
if (! in_array($action, ['status', 'voice', 'publish', 'retry'], true)) {
    throw new RuntimeException('Unknown radio operation.');
}
if (! Illuminate\Support\Facades\Schema::hasTable('radio_bulletins')) {
    throw new RuntimeException('Run the radio migration first.');
}
if ($action !== 'status') {
    if (! App\Models\RadioBulletin::available() || ! ctype_digit((string) $id)) {
        throw new RuntimeException('Enable radio and supply a bulletin ID.');
    }
    $lock = Illuminate\Support\Facades\Cache::lock('radio-pipeline', 1800);
    if (! $lock->get()) {
        throw new RuntimeException('A radio worker is running. Try again shortly.');
    }
    try {
        $b = App\Models\RadioBulletin::findOrFail($id);
        $service = app(App\Services\RadioService::class);
        if ($action === 'publish') {
            $service->publish($b);
        } elseif ($action === 'retry') {
            if ($b->status !== 'failed') {
                throw new RuntimeException('Only a failed edition can be retried.');
            }
            $b->update(['status' => 'queued', 'attempts' => 0, 'failure_reason' => null]);
        } else {
            if ($b->status !== 'draft') {
                throw new RuntimeException('Only a reviewed draft can be voiced.');
            }
            $service->assertFresh($b);
            $b->update(['status' => 'queued', 'stage' => 'audio', 'attempts' => 0]);
        }
    } finally {
        $lock->release();
    }
}
$latest = $id ? App\Models\RadioBulletin::findOrFail($id) : App\Models\RadioBulletin::orderByDesc('slot')->first();
$feedHealth = null;
if (App\Models\RadioBulletin::available()) {
    try {
        $feedHealth = ['status' => app(App\Http\Controllers\RadioController::class)->feed()->getStatusCode()];
    } catch (Throwable $error) {
        $feedHealth = ['error' => get_class($error), 'message' => $error->getMessage()];
    }
}
echo json_encode([
    'enabled' => config('radio.enabled'), 'auto_publish' => config('radio.auto_publish'),
    'voice' => config('radio.voice'), 'node' => config('radio.node'), 'ffmpeg' => config('radio.ffmpeg'),
    'editor_model' => config('services.openrouter.model'),
    'news_sources' => config('radio.news_sources'),
    'feed_health' => $feedHealth,
    'bulletin' => $latest?->only(['id', 'slot', 'status', 'stage', 'attempts', 'failure_reason', 'script', 'facts',
        'duration_seconds', 'audio_bytes', 'recorded_at', 'published_at']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
