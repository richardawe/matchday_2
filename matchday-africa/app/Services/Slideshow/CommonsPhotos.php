<?php

namespace App\Services\Slideshow;

use App\Models\Player;
use App\Support\PlayerNames;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Large, freely licensed player photos for slide 01, from Wikimedia Commons.
 *
 * The file comes from the player's Wikidata item (image, P18): a footballer (P106 = association
 * football player) whose name matches, and whose date of birth matches ours when we have one, or
 * the only such item otherwise. The Commons API then gives the licence (LicenseShortName), the
 * author (Artist) and a 1080 px rendition; only CC0, public domain, CC BY and CC BY-SA pass. The
 * download, crop and credit are kept on the player (metadata.commons_photo) so a deck shows the
 * credit it was made with. An editor can pin a Commons file with slideshow:photo.
 */
class CommonsPhotos
{
    private const WIKIDATA = 'https://www.wikidata.org/w/api.php';
    private const COMMONS = 'https://commons.wikimedia.org/w/api.php';
    private const FOOTBALLER = 'Q937857';

    /** Slide 01's photo window (width × height) the crop is made for. */
    public const BOX = [784, 640];

    /** The stored photo, looking it up on first use: ['file', 'crop', 'credit', 'artist', 'licence', …] or null. */
    public function forPlayer(Player $player): ?array
    {
        $stored = data_get($player->metadata, 'commons_photo');
        if (is_array($stored) && ($stored['status'] ?? null) === 'ok') {
            $file = storage_path('app/'.$stored['path']);
            if (is_file($file)) {
                return $stored + ['file' => $file];
            }
        }
        if (is_array($stored) && ($stored['status'] ?? null) === 'none'
            && now()->diffInDays($stored['checked_at'] ?? now()->subYear(), true) < (int) config('slideshow.commons.retry_days', 14)) {
            return null;
        }
        if (!config('slideshow.commons.enabled')) {
            return null;
        }

        return $this->lookup($player, $stored['pinned'] ?? null);
    }

    /** Finds (or, with $fileName, checks the pinned Commons file), downloads and stores the photo. */
    public function lookup(Player $player, ?string $fileName = null): ?array
    {
        try {
            [$fileName, $qid] = $fileName ? [$fileName, null] : $this->wikidataImage($player);
            if (!$fileName) {
                return $this->remember($player, ['status' => 'none', 'reason' => 'No unambiguous Wikidata footballer with an image.']);
            }
            $info = $this->imageInfo($fileName);
            if (!$info) {
                return $this->remember($player, ['status' => 'none', 'reason' => "Commons has no usable image for {$fileName}.", 'pinned' => $fileName]);
            }
            if (!self::freeLicence($info['licence'])) {
                return $this->remember($player, ['status' => 'none', 'reason' => "Licence not allowed: {$info['licence']}.", 'pinned' => $fileName]);
            }
            if ($info['width'] < (int) config('slideshow.commons.min_width', 700)) {
                return $this->remember($player, ['status' => 'none', 'reason' => 'Image too small.', 'pinned' => $fileName]);
            }
            $bytes = $this->http()->timeout(30)->get($info['thumb'])->throw()->body();
            $image = @imagecreatefromstring($bytes);
            if (!$image) {
                return $this->remember($player, ['status' => 'none', 'reason' => 'Could not read the image.', 'pinned' => $fileName]);
            }
            $path = "slideshow-photos/{$player->id}.jpg";
            File::ensureDirectoryExists(storage_path('app/slideshow-photos'));
            imagejpeg($image, storage_path('app/'.$path), 90);
            $crop = self::crop(imagesx($image), imagesy($image), self::BOX[0], self::BOX[1]);
            imagedestroy($image);

            return $this->remember($player, [
                'status' => 'ok', 'path' => $path, 'crop' => $crop, 'wikidata' => $qid, 'title' => $info['title'],
                'source_url' => $info['page'], 'licence' => $info['licence'], 'licence_url' => $info['licence_url'],
                'artist' => $info['artist'], 'credit' => self::credit($info['artist'], $info['licence']),
                'pinned' => $qid ? null : $fileName,
            ]);
        } catch (Throwable $e) {
            Log::warning('Commons photo lookup failed', ['player_id' => $player->id, 'error' => $e->getMessage()]);

            return null; // Network trouble: try again next time, nothing remembered.
        }
    }

    public static function freeLicence(?string $licence): bool
    {
        $licence = trim((string) $licence);

        return (bool) preg_match('/^(CC0(\s.*)?|Public domain.*|PD(-\S+)?|CC BY(-SA)? \d(\.\d)?(\s.*)?)$/i', $licence);
    }

    /** "Jane Doe / Wikimedia Commons / CC BY-SA 4.0" */
    public static function credit(string $artist, string $licence): string
    {
        return Str::limit($artist !== '' ? $artist : 'Unknown author', 60, '…').' / Wikimedia Commons / '.$licence;
    }

    /**
     * The largest box of the window's shape inside the photo, centred across and a little above
     * centre down (faces sit in the top third of portraits): ['x', 'y', 'w', 'h'].
     */
    public static function crop(int $width, int $height, int $boxW, int $boxH): array
    {
        $ratio = $boxW / $boxH;
        if ($width / $height > $ratio) {
            $h = $height;
            $w = (int) round($h * $ratio);
            $x = (int) round(($width - $w) / 2);
            $y = 0;
        } else {
            $w = $width;
            $h = (int) round($w / $ratio);
            $x = 0;
            $y = (int) round(($height - $h) * 0.2);
        }

        return ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /** [Commons file name, Wikidata id] for the player's item, or [null, null]. */
    private function wikidataImage(Player $player): array
    {
        $search = $this->http()->get(self::WIKIDATA, ['action' => 'wbsearchentities', 'search' => $player->name, 'language' => 'en',
            'type' => 'item', 'limit' => 10, 'format' => 'json'])->throw()->json('search', []);
        $ids = collect($search)->filter(fn ($row) => PlayerNames::same($player->name, (string) ($row['label'] ?? '')))->pluck('id')->take(10)->all();
        if (!$ids) {
            return [null, null];
        }
        $entities = $this->http()->get(self::WIKIDATA, ['action' => 'wbgetentities', 'ids' => implode('|', $ids), 'props' => 'claims',
            'format' => 'json'])->throw()->json('entities', []);
        $born = $player->date_of_birth?->format('Y-m-d');
        $candidates = collect($entities)->filter(function ($entity) {
            $jobs = collect(data_get($entity, 'claims.P106', []))->pluck('mainsnak.datavalue.value.id');

            return $jobs->contains(self::FOOTBALLER) && data_get($entity, 'claims.P18.0.mainsnak.datavalue.value');
        });
        if ($born) {
            $candidates = $candidates->filter(fn ($entity) => str_starts_with(ltrim((string) data_get($entity, 'claims.P569.0.mainsnak.datavalue.value.time'), '+'), $born));
        }
        if ($candidates->count() !== 1) {
            return [null, null]; // None, or more than one footballer of that name: never guess.
        }
        $entity = $candidates->first();

        return [(string) data_get($entity, 'claims.P18.0.mainsnak.datavalue.value'), (string) data_get($entity, 'id')];
    }

    private function imageInfo(string $fileName): ?array
    {
        $title = 'File:'.preg_replace('/^File:/i', '', trim($fileName));
        $pages = $this->http()->get(self::COMMONS, ['action' => 'query', 'titles' => $title, 'prop' => 'imageinfo',
            'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 1080, 'format' => 'json'])->throw()->json('query.pages', []);
        $info = data_get(collect($pages)->first(), 'imageinfo.0');
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
            return null;
        }
        $meta = $info['extmetadata'] ?? [];
        $artist = trim(html_entity_decode(strip_tags((string) data_get($meta, 'Artist.value', '')), ENT_QUOTES | ENT_HTML5));

        return [
            'title' => (string) data_get(collect($pages)->first(), 'title', $title),
            'page' => (string) ($info['descriptionurl'] ?? 'https://commons.wikimedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $title))),
            'thumb' => (string) ($info['thumburl'] ?? $info['url']),
            'width' => (int) ($info['width'] ?? 0),
            'licence' => trim((string) data_get($meta, 'LicenseShortName.value', '')),
            'licence_url' => (string) data_get($meta, 'LicenseUrl.value', ''),
            'artist' => preg_replace('/\s+/', ' ', $artist),
        ];
    }

    private function remember(Player $player, array $photo): ?array
    {
        $photo['checked_at'] = now()->toIso8601String();
        $meta = $player->fresh()?->metadata;
        $meta = is_array($meta) ? $meta : [];
        $meta['commons_photo'] = array_filter($photo, fn ($v) => $v !== null);
        $player->forceFill(['metadata' => $meta])->saveQuietly();
        $player->metadata = $meta;

        return $photo['status'] === 'ok' ? $meta['commons_photo'] + ['file' => storage_path('app/'.$photo['path'])] : null;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['User-Agent' => (string) config('slideshow.commons.user_agent')])->timeout(15)->acceptJson();
    }
}
