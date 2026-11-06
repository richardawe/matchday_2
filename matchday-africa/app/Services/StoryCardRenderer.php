<?php

namespace App\Services;

use App\Models\MatchMoment;
use App\Support\Armies;
use GdImage;
use Illuminate\Support\Facades\File;

/**
 * Draws a story chapter as a PNG with GD (built into PHP, so it runs on the cPanel host):
 * the clubs' crest-free armies under team-coloured light, the Griot's caption, the score
 * and a stripe band in team colours; goal and red-card chapters add the player's photo when we
 * have one (public/generated/players). Cards are cached on disk per chapter, voice, size and photo.
 */
class StoryCardRenderer
{
    public const FORMATS = ['story' => [1080, 1920], 'feed' => [1080, 1350], 'og' => [1200, 630]];

    protected const PAPER = [232, 225, 211];
    protected const MUTED = [163, 156, 143];
    protected const GOLD = [237, 199, 129];
    protected const BLOOD = [214, 72, 60];

    public function __construct(private Griot $griot) {}

    /** Absolute path to the rendered PNG, drawing it on first request. */
    public function path(MatchMoment $moment, string $voice, string $format): string
    {
        $photo = $this->photo($moment);
        $stamp = $photo ? '-p'.substr(sha1($photo.filemtime($photo)), 0, 8) : '';
        $file = storage_path("app/story-cards/{$moment->id}-{$voice}-{$format}-".($moment->updated_at?->timestamp ?? 0).$stamp.'.png');
        if (!is_file($file)) {
            File::ensureDirectoryExists(dirname($file));
            imagepng($this->draw($moment, $voice, $format), $file, 6);
        }

        return $file;
    }

    public function draw(MatchMoment $moment, string $voice, string $format): GdImage
    {
        [$w, $h] = self::FORMATS[$format];
        $match = $moment->match()->with(['homeTeam', 'awayTeam'])->first();
        $home = Armies::for($match->homeTeam);
        $away = Armies::for($match->awayTeam);
        $hero = $moment->side === 'away' ? $away : $home;
        $foe = $moment->side === 'away' ? $home : $away;
        $both = $moment->side === null;

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagefill($img, 0, 0, $this->color($img, [7, 8, 7]));

        $landscape = $format === 'og';
        $glow = $both ? [$home['colours'][0] ?? '#d2a052', $away['colours'][0] ?? '#d2a052'] : [$hero['colours'][0] ?? '#d2a052'];
        if ($both) {
            $this->glow($img, (int) ($w * 0.28), (int) ($h * 0.42), (int) ($w * 0.55), $glow[0]);
            $this->glow($img, (int) ($w * 0.72), (int) ($h * 0.42), (int) ($w * 0.55), $glow[1]);
        } else {
            $this->glow($img, (int) ($w * ($landscape ? 0.7 : 0.55)), (int) ($h * 0.4), (int) ($w * ($landscape ? 0.5 : 0.85)), $glow[0]);
        }

        if ($both) {
            $size = $landscape ? $h * 1.0 : $h * 0.6;
            $this->army($img, $home, $size, $landscape ? $w * 0.56 : $w * 0.26, $landscape ? $h : $h * 0.8, flip: true);
            $this->army($img, $away, $size, $landscape ? $w * 0.86 : $w * 0.74, $landscape ? $h : $h * 0.8);
        } else {
            $this->army($img, $foe, $landscape ? $h * 0.75 : $h * 0.5, $landscape ? $w * 0.5 : $w * 0.14, $landscape ? $h : $h * 0.74, flip: true, ghost: true);
            $this->army($img, $hero, $landscape ? $h * 1.08 : $h * 0.74, $landscape ? $w * 0.74 : $w * 0.58, $landscape ? $h * 1.04 : $h * 0.84, fallen: $moment->type === 'red_card');
        }

        // Shade the text areas so captions read over the art.
        if ($landscape) {
            $this->fade($img, 0, 0, (int) ($w * 0.62), $h, 'right', 15, 127);
        } else {
            $this->fade($img, 0, 0, $w, (int) ($h * 0.16), 'down', 20, 127);
            $this->fade($img, 0, (int) ($h * 0.46), $w, (int) ($h * 0.54), 'down', 127, 8);
        }

        // The scorer's (or sent-off player's) photo in a round badge beside their army. Owner's
        // choice, 6 Nov 2026: player photos may show kit crests (an exception to AGENTS rule 6).
        if ($file = $this->photo($moment)) {
            $radius = (int) ($landscape ? $h * 0.17 : $w * 0.15);
            [$cx, $cy] = $landscape ? [(int) ($w * 0.9), (int) ($h * 0.3)] : [(int) ($w * 0.8), (int) ($h * ($format === 'story' ? 0.32 : 0.36))];
            $this->badge($img, $file, $cx, $cy, $radius, $hero['colours'][0] ?? '#d2a052', $moment->type === 'red_card');
        }

        [$headline, $line] = $this->griot->tell($moment, $voice);
        $score = trim(preg_replace('/\s+FC$/i', '', (string) $match->homeTeam?->name)).'  '.$moment->home_score.'–'.$moment->away_score.'  '.trim(preg_replace('/\s+FC$/i', '', (string) $match->awayTeam?->name));
        $pad = $landscape ? 56 : 64;
        $textWidth = $landscape ? (int) ($w * 0.56) : $w - 2 * $pad;
        $red = $moment->type === 'red_card';

        // Top row: competition, then the chapter tag in a box.
        $this->text($img, 'mono', $landscape ? 20 : 26, self::MUTED, $pad, $pad + 10, 'PREMIER LEAGUE');
        $tag = $this->griot->tag($moment);
        $tagSize = $landscape ? 22 : 28;
        $box = imagettfbbox($tagSize, 0, $this->font('mono'), $tag);
        $tagW = $box[2] - $box[0] + 36;
        $tagX = $landscape ? $pad : $w - $pad - $tagW;
        $tagY = $landscape ? $pad + 38 : $pad - 16;
        imagesetthickness($img, 2);
        imagerectangle($img, $tagX, $tagY, $tagX + $tagW, $tagY + $tagSize + 26, $this->color($img, $red ? self::BLOOD : self::GOLD));
        $this->text($img, 'mono', $tagSize, $red ? self::BLOOD : self::GOLD, $tagX + 18, $tagY + $tagSize + 12, $tag);

        // Bottom block, laid out upwards from the stripe band.
        $band = $landscape ? 14 : 22;
        $this->kente($img, $h - $band, $w, $band, $hero['colours'] ?? ['#d2a052', '#e8e1d3']);
        $y = $h - $band - ($landscape ? 34 : 46);
        $this->text($img, 'mono', $landscape ? 16 : 22, self::MUTED, $pad, $y, 'MATCHDAY.AFRICA  ·  TOLD BY THE GRIOT');
        // Half- and full-time captions already state the score.
        if (!in_array($moment->type, ['half_time', 'full_time'], true)) {
            $y -= $landscape ? 46 : 70;
            $this->text($img, 'mono', $landscape ? 24 : 36, self::GOLD, $pad, $y, $this->fit($score, 'mono', $landscape ? 24 : 36, $textWidth));
        }
        $y -= $landscape ? 44 : 64;
        $lineSize = $landscape ? 26 : 38;
        $lineRows = $this->wrap($line, 'body', $lineSize, $textWidth);
        foreach (array_reverse($lineRows) as $row) {
            $this->text($img, 'body', $lineSize, [214, 207, 192], $pad, $y, $row);
            $y -= (int) ($lineSize * 1.45);
        }
        $y -= $landscape ? 6 : 14;
        $headSize = $landscape ? 64 : ($format === 'story' ? 124 : 108);
        foreach (array_reverse($this->wrap(mb_strtoupper($headline), 'display', $headSize, $textWidth)) as $row) {
            $this->text($img, 'display', $headSize, self::PAPER, $pad, $y, $row);
            $y -= (int) ($headSize * 1.4); // GD sizes are points; Anton's caps run about 1.3× that in pixels
        }

        return $img;
    }

    /** The local photo file of the player a goal or red-card chapter is about, or null. */
    protected function photo(MatchMoment $moment): ?string
    {
        if (!in_array($moment->type, ['goal', 'red_card'], true) || !$moment->player_name) {
            return null;
        }
        try {
            return PlayerPhotos::forMatch($moment->match, $moment->side, $moment->player_name)['path'] ?? null;
        } catch (\Throwable) {
            return null; // a card always draws, with or without the photo
        }
    }

    /** Draws a photo cropped to a circle, with an ink ring and a ring in the side's colour. */
    protected function badge(GdImage $img, string $file, int $cx, int $cy, int $radius, string $ring, bool $greyscale): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($file));
        if (!$source) {
            return;
        }
        $size = 2 * $radius;
        $face = imagecreatetruecolor($size, $size);
        imagealphablending($face, false);
        imagesavealpha($face, true);
        imagefill($face, 0, 0, imagecolorallocatealpha($face, 0, 0, 0, 127));
        // Cover the circle: crop the photo to a square from the top (faces sit high in headshots).
        $sw = imagesx($source);
        $sh = imagesy($source);
        $side = min($sw, $sh);
        imagecopyresampled($face, $source, 0, 0, (int) (($sw - $side) / 2), 0, $size, $size, $side, $side);
        if ($greyscale) {
            imagefilter($face, IMG_FILTER_GRAYSCALE);
        }
        $clear = imagecolorallocatealpha($face, 0, 0, 0, 127);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (($x - $radius + 0.5) ** 2 + ($y - $radius + 0.5) ** 2 > $radius ** 2) {
                    imagesetpixel($face, $x, $y, $clear);
                }
            }
        }
        $ringWidth = max(6, (int) ($radius * 0.07));
        imagefilledellipse($img, $cx, $cy, 2 * ($radius + 2 * $ringWidth), 2 * ($radius + 2 * $ringWidth), $this->color($img, $this->rgb($ring)));
        imagefilledellipse($img, $cx, $cy, 2 * ($radius + $ringWidth), 2 * ($radius + $ringWidth), $this->color($img, [7, 8, 7]));
        imagefilledellipse($img, $cx, $cy, $size, $size, $this->color($img, [232, 225, 211]));
        imagecopy($img, $face, $cx - $radius, $cy - $radius, 0, 0, $size, $size);
        imagedestroy($face);
        imagedestroy($source);
    }

    protected function army(GdImage $img, ?array $army, float $height, float $centerX, float $bottom, bool $flip = false, bool $ghost = false, bool $fallen = false): void
    {
        if (!$army || !$army['cutout_path'] || !is_file($army['cutout_path'])) {
            return;
        }
        $art = imagecreatefromwebp($army['cutout_path']);
        imagealphablending($art, false);
        imagesavealpha($art, true);
        if ($flip) {
            imageflip($art, IMG_FLIP_HORIZONTAL);
        }
        if ($ghost || $fallen) {
            imagefilter($art, IMG_FILTER_GRAYSCALE);
            imagefilter($art, IMG_FILTER_BRIGHTNESS, $ghost ? -40 : -25);
        }
        if ($fallen) {
            imagefilter($art, IMG_FILTER_COLORIZE, 60, 0, 0);
        }
        if ($ghost) {
            imagefilter($art, IMG_FILTER_COLORIZE, 0, 0, 0, 80);
        }
        $scale = $height / imagesy($art);
        $dw = (int) (imagesx($art) * $scale);
        $dh = (int) $height;
        imagecopyresampled($img, $art, (int) ($centerX - $dw / 2), (int) ($bottom - $dh), 0, 0, $dw, $dh, imagesx($art), imagesy($art));
    }

    protected function glow(GdImage $img, int $cx, int $cy, int $radius, string $hex): void
    {
        $rgb = $this->rgb($hex);
        for ($i = 24; $i >= 1; $i--) {
            $r = (int) ($radius * $i / 24);
            imagefilledellipse($img, $cx, $cy, $r * 2, (int) ($r * 2.2), imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], 124));
        }
    }

    /** A linear fade of black from one alpha to another across a rectangle. */
    protected function fade(GdImage $img, int $x, int $y, int $w, int $h, string $direction, int $from, int $to): void
    {
        $steps = $direction === 'right' ? $w : $h;
        for ($i = 0; $i < $steps; $i += 2) {
            $alpha = (int) round($from + ($to - $from) * $i / max(1, $steps - 1));
            $c = imagecolorallocatealpha($img, 7, 8, 7, max(0, min(127, $alpha)));
            $direction === 'right'
                ? imagefilledrectangle($img, $x + $i, $y, $x + $i + 1, $y + $h, $c)
                : imagefilledrectangle($img, $x, $y + $i, $x + $w, $y + $i + 1, $c);
        }
    }

    /** Kente-style stripe band in the team's colours with gold and black. */
    protected function kente(GdImage $img, int $y, int $w, int $h, array $colours): void
    {
        $pattern = [[$colours[0], 46], ['#d2a052', 12], [$colours[1] ?? '#e8e1d3', 28], ['#070807', 8], ['#d2a052', 12], ['#070807', 6]];
        for ($x = 0; $x < $w;) {
            foreach ($pattern as [$hex, $width]) {
                imagefilledrectangle($img, $x, $y, min($w, $x + $width), $y + $h, $this->color($img, $this->rgb($hex)));
                $x += $width;
            }
        }
    }

    protected function text(GdImage $img, string $face, int $size, array $rgb, int $x, int $y, string $text): void
    {
        imagettftext($img, $size, 0, $x, $y, $this->color($img, $rgb), $this->font($face), $text);
    }

    protected function wrap(string $text, string $face, int $size, int $width): array
    {
        $rows = [];
        $row = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $row === '' ? $word : "{$row} {$word}";
            $box = imagettfbbox($size, 0, $this->font($face), $try);
            if ($row !== '' && $box[2] - $box[0] > $width) {
                $rows[] = $row;
                $row = $word;
            } else {
                $row = $try;
            }
        }

        return array_slice(array_merge($rows, [$row]), 0, 3);
    }

    /** Shortens text with an ellipsis until it fits one line. */
    protected function fit(string $text, string $face, int $size, int $width): string
    {
        while (mb_strlen($text) > 4) {
            $box = imagettfbbox($size, 0, $this->font($face), $text);
            if ($box[2] - $box[0] <= $width) {
                return $text;
            }
            $text = rtrim(mb_substr($text, 0, -2)).'…';
        }

        return $text;
    }

    protected function font(string $face): string
    {
        return resource_path('fonts/'.['display' => 'Anton-Regular.ttf', 'body' => 'IBMPlexSans.ttf', 'mono' => 'IBMPlexMono-SemiBold.ttf'][$face]);
    }

    protected function color(GdImage $img, array $rgb): int
    {
        return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    }

    protected function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
