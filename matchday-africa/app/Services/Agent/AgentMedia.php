<?php

namespace App\Services\Agent;

use App\Models\Slideshow;
use App\Models\StoryVideo;
use App\Models\User;
use App\Services\SocialQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Videos the agent uploads for the social accounts (POST /api/agent/media): stored as static files at
 * public/generated/agent/{uuid}.mp4, which the host serves with Content-Type video/mp4 and byte ranges
 * (social platforms and iPhones need ranges; PHP responses lose them). A private record per file sits in
 * storage/app/agent-media/{uuid}.json. Files are deleted KEEP_DAYS after upload (agent:media-prune).
 */
class AgentMedia
{
    public const KEEP_DAYS = 7;

    public const MAX_KB = 204800; // 200 MB

    public function dir(): string
    {
        return public_path('generated/agent');
    }

    /** True when the file starts like an MP4 (an ISO base media "ftyp" box). */
    public static function looksLikeMp4(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, 12);

        return is_string($head) && strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp';
    }

    /** The render a file belongs to ("story:45", "slideshow:7"), or null when it does not exist. */
    public static function render(?string $id): ?array
    {
        if (!$id || !preg_match('/^(story|slideshow):(\d+)$/', $id, $m)) {
            return null;
        }
        $exists = $m[1] === 'story' ? StoryVideo::whereKey((int) $m[2])->exists() : Slideshow::whereKey((int) $m[2])->exists();

        return $exists ? ['id' => $id, 'type' => $m[1], 'key' => (int) $m[2]] : null;
    }

    public function store(UploadedFile $file, User $by, ?string $renderId = null): array
    {
        $uuid = (string) Str::uuid();
        File::ensureDirectoryExists($this->dir());
        $target = $this->dir()."/{$uuid}.mp4";
        // Copy then rename, so a half-written file is never served.
        File::copy($file->getRealPath(), "{$target}.part");
        rename("{$target}.part", $target);
        @chmod($target, 0644);
        $bytes = filesize($target);
        $created = now();
        $record = ['uuid' => $uuid, 'path' => "generated/agent/{$uuid}.mp4", 'bytes' => $bytes, 'render_id' => $renderId,
            'uploaded_by' => $by->id, 'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 200),
            'created_at' => $created->utc()->toIso8601ZuluString(), 'expires_at' => $created->copy()->addDays(self::KEEP_DAYS)->utc()->toIso8601ZuluString()];
        File::ensureDirectoryExists(storage_path('app/agent-media'));
        file_put_contents(storage_path("app/agent-media/{$uuid}.json"), json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['id' => $uuid, 'url' => SocialQueue::https(asset($record['path'])), 'content_type' => 'video/mp4', 'bytes' => $bytes,
            'render_id' => $renderId, 'created_at' => $record['created_at'], 'expires_at' => $record['expires_at']];
    }

    /** Deletes uploads (and their records) older than KEEP_DAYS, plus stale half-written files. Returns how many files went. */
    public function prune(): int
    {
        clearstatcache();
        $cutoff = now()->subDays(self::KEEP_DAYS)->getTimestamp();
        $deleted = 0;
        foreach (glob($this->dir().'/*') ?: [] as $file) {
            $name = basename($file);
            $stale = str_ends_with($name, '.part') ? filemtime($file) < now()->subDay()->getTimestamp() : false;
            if (!preg_match('/^[0-9a-f-]{36}\.mp4(\.part)?$/', $name) || !is_file($file) || (!$stale && filemtime($file) >= $cutoff)) {
                continue; // only our own uploads, never anything else in the folder
            }
            if (@unlink($file)) {
                $deleted++;
                @unlink(storage_path('app/agent-media/'.substr($name, 0, 36).'.json'));
            }
        }
        foreach (glob(storage_path('app/agent-media/*.json')) ?: [] as $json) {
            if (!is_file($this->dir().'/'.basename($json, '.json').'.mp4') && filemtime($json) < $cutoff) {
                @unlink($json);
            }
        }

        return $deleted;
    }
}
