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
        $look = self::cartoonLook() ? '-cartoon' : '';
        $file = storage_path("app/story-cards/{$moment->id}-{$voice}-{$format}-".($moment->updated_at?->timestamp ?? 0).$stamp.$look.'.png');
        if (!is_file($file)) {
            File::ensureDirectoryExists(dirname($file));
            imagepng($this->draw($moment, $voice, $format), $file, 6);
        }

        return $file;
    }

    /** The site-wide look (SITE_STYLE) decides the card, not a visitor's preview cookie: the same card goes to Buffer. */
    public static function cartoonLook(): bool
    {
        return config('site.style') === 'cartoon';
    }

    public function draw(MatchMoment $moment, string $voice, string $format): GdImage
    {
        if (self::cartoonLook()) {
            return $this->drawCartoonChapter($moment, $voice, $format);
        }
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

    protected const C_INK = [11, 11, 11];
    protected const C_SUN = [255, 210, 63];
    protected const C_RAY = [255, 196, 31];
    protected const C_CREAM = [255, 248, 231];
    protected const C_RED = [255, 75, 62];
    protected const C_WHITE = [255, 255, 255];
    protected const C_SOFT = [74, 68, 59];

    /**
     * The cartoon chapter card (docs/CARTOON.md, same look as the /stream goal screen): a sunburst
     * for goals (ink for red cards), a red tag, the side's solid army cut-out in a club-coloured
     * comic panel with the player's photo badge, and a cream panel with the Griot's words and the score.
     */
    protected function drawCartoonChapter(MatchMoment $moment, string $voice, string $format): GdImage
    {
        [$w, $h] = self::FORMATS[$format];
        $match = $moment->match()->with(['homeTeam', 'awayTeam', 'league'])->first();
        $home = Armies::for($match->homeTeam);
        $away = Armies::for($match->awayTeam);
        $both = $moment->side === null;
        $hero = $moment->side === 'away' ? $away : $home;
        $heroTeam = $moment->side === 'away' ? $match->awayTeam : $match->homeTeam;
        $red = $moment->type === 'red_card';
        $landscape = $format === 'og';

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagefill($img, 0, 0, $this->color($img, $red ? self::C_INK : self::C_SUN));

        $pad = $landscape ? 36 : 48;
        $shadow = $landscape ? 10 : 14;
        $border = $landscape ? 6 : 8;
        // Art panel and text panel.
        if ($landscape) {
            [$ax, $ay, $aw, $ah] = [(int) ($w * 0.53), $pad, (int) ($w * 0.47) - $pad - $shadow, $h - 2 * $pad - $shadow];
            [$tx, $ty, $tw, $th] = [$pad, $pad, (int) ($w * 0.53) - 2 * $pad, $h - 2 * $pad - $shadow];
        } else {
            $top = $pad + 76 + 40;
            $ah = $format === 'story' ? 1080 : 600;
            [$ax, $ay, $aw] = [$pad, $top, $w - 2 * $pad - $shadow];
            [$tx, $ty, $tw] = [$pad, $ay + $ah + $shadow + 36, $w - 2 * $pad - $shadow];
            $th = $h - $pad - $shadow - $ty;
        }

        if ($moment->type === 'goal') {
            $this->rays($img, $ax + (int) ($aw / 2), $ay + (int) ($ah / 2));
        }

        // The armies in their panel.
        $this->comicPanel($img, $ax, $ay, $aw, $ah, $this->rgb(($both ? $home : $hero)['colours'][0] ?? '#4a443b'), $border, $shadow);
        if ($both) {
            $half = (int) (($aw - 2 * $border) / 2);
            imagefilledrectangle($img, $ax + $border + $half, $ay + $border, $ax + $aw - $border, $ay + $ah - $border, $this->color($img, $this->rgb($away['colours'][0] ?? '#4a443b')));
            $this->cutout($img, $home, $match->homeTeam, $ax + $border, $ay + $border, $half, $ah - 2 * $border, false, false);
            $this->cutout($img, $away, $match->awayTeam, $ax + $border + $half, $ay + $border, $half, $ah - 2 * $border, true, false);
        } else {
            $this->cutout($img, $hero, $heroTeam, $ax + $border, $ay + $border, $aw - 2 * $border, $ah - 2 * $border, false, $red);
        }
        if ($photo = $this->photo($moment)) {
            $radius = (int) ($landscape ? $h * 0.16 : ($format === 'story' ? 170 : 140));
            $this->badge($img, $photo, $ax + $aw - $radius - (int) ($radius * 0.35), $ay + $ah - $radius - (int) ($radius * 0.35), $radius, ($hero['colours'][0] ?? '#d2a052'), $red);
        }

        // The tag ("GOAL · 41′") and the MD tile.
        $tag = mb_strtoupper($this->griot->tag($moment));
        $tagSize = $landscape ? 26 : 40;
        $tb = imagettfbbox($tagSize, 0, $this->font('display'), $tag);
        $tagW = $tb[2] - $tb[0] + ($landscape ? 28 : 44);
        $tagH = $landscape ? 48 : 76;
        [$tagX, $tagY] = $landscape ? [$tx, $ty] : [$pad, $pad];
        $this->comicPanel($img, $tagX, $tagY, $tagW, $tagH, self::C_RED, $landscape ? 4 : 5, $landscape ? 5 : 8);
        $this->text($img, 'display', $tagSize, self::C_WHITE, $tagX + ($landscape ? 14 : 22), $tagY + (int) ($tagH * 0.5 + $tagSize * 0.62), $tag);
        if (!$landscape) {
            $this->comicPanel($img, $w - $pad - 96, $pad - 10, 96, 96, self::C_INK, 0, 0);
            $this->text($img, 'display', 46, self::C_SUN, $w - $pad - 82, $pad + 58, 'MD');
            $league = mb_strtoupper((string) ($match->league?->name ?: 'Football'));
            $lx = $tagX + $tagW + 30;
            $this->text($img, 'mono', 22, $red ? self::C_CREAM : self::C_INK, $lx, $pad + 48, $this->fit($league, 'mono', 22, $w - $pad - 96 - 20 - $lx));
        }

        // The words.
        $textTop = $landscape ? $tagY + $tagH + 24 : $ty;
        $this->comicPanel($img, $tx, $textTop, $tw, $ty + $th - $textTop, self::C_CREAM, $border, $shadow);
        [$headline, $line] = $this->griot->tell($moment, $voice);
        $inner = $landscape ? 26 : 36;
        $x = $tx + $inner;
        $width = $tw - 2 * $inner;
        $footSize = $landscape ? 14 : 22;
        $foot = $ty + $th - $inner;
        $this->text($img, 'mono', $footSize, self::C_SOFT, $x, $foot, $this->fit('MATCHDAY.AFRICA  ·  TOLD BY THE GRIOT', 'mono', $footSize, $width));

        // Measure the headline, line and score, then centre them in the panel above the footer.
        $headSize = $landscape ? 48 : ($format === 'story' ? 120 : 88);
        $minHead = $landscape ? 32 : 56;
        while ($headSize > $minHead && count($this->wrap(mb_strtoupper($headline), 'display', $headSize, $width)) > 2) {
            $headSize -= 4;
        }
        $heads = array_slice($this->wrap(mb_strtoupper($headline), 'display', $headSize, $width), 0, 2);
        $lineSize = $landscape ? 20 : ($format === 'story' ? 40 : 34);
        $lines = array_slice($this->wrap($line, 'body', $lineSize, $width), 0, $landscape ? 3 : ($format === 'story' ? 4 : 2));
        $score = null;
        if (!in_array($moment->type, ['half_time', 'full_time', 'kickoff'], true)) {
            $name = fn ($team) => mb_strtoupper(trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $team?->name)));
            $score = $name($match->homeTeam).'  '.$moment->home_score.'–'.$moment->away_score.'  '.$name($match->awayTeam);
        }
        $scoreSize = $landscape ? 22 : ($format === 'story' ? 40 : 34);
        $boxH = (int) ($scoreSize * 1.9);
        $gap = $landscape ? 12 : 20;
        $block = count($heads) * (int) ($headSize * 1.4) + $gap + count($lines) * (int) ($lineSize * 1.45) + ($score ? $gap + $boxH : 0);
        $areaTop = $textTop + $inner;
        $areaBottom = $foot - $footSize - 2 * $gap;
        $y = $areaTop + max(0, (int) (($areaBottom - $areaTop - $block) / 2));

        foreach ($heads as $row) {
            $this->text($img, 'display', $headSize, self::C_INK, $x, $y + (int) ($headSize * 1.3), $row);
            $y += (int) ($headSize * 1.4);
        }
        $y += $gap;
        foreach ($lines as $row) {
            $this->text($img, 'body', $lineSize, self::C_INK, $x, $y + (int) ($lineSize * 1.15), $row);
            $y += (int) ($lineSize * 1.45);
        }
        if ($score) {
            $y += $gap;
            $score = $this->fit($score, 'display', $scoreSize, $width - 32);
            $sb = imagettfbbox($scoreSize, 0, $this->font('display'), $score);
            $this->comicPanel($img, $x, $y, min($width, $sb[2] - $sb[0] + 32), $boxH, self::C_SUN, 4, 0);
            $this->text($img, 'display', $scoreSize, self::C_INK, $x + 16, $y + (int) ($boxH * 0.73), $score);
        }

        return $img;
    }

    /** Sunburst rays from a point, behind everything else. */
    protected function rays(GdImage $img, int $cx, int $cy): void
    {
        $ray = $this->color($img, self::C_RAY);
        for ($i = 0; $i < 28; $i += 2) {
            $a1 = deg2rad($i * (360 / 28));
            $a2 = deg2rad(($i + 1) * (360 / 28));
            imagefilledpolygon($img, [$cx, $cy, (int) ($cx + cos($a1) * 3000), (int) ($cy + sin($a1) * 3000), (int) ($cx + cos($a2) * 3000), (int) ($cy + sin($a2) * 3000)], $ray);
        }
    }

    /** A filled box with an ink border and an offset ink shadow (comic panel). */
    protected function comicPanel(GdImage $img, int $x, int $y, int $w, int $h, array $fill, int $border, int $shadow): void
    {
        $ink = $this->color($img, self::C_INK);
        if ($shadow) {
            imagefilledrectangle($img, $x + $shadow, $y + $shadow, $x + $w + $shadow, $y + $h + $shadow, $ink);
        }
        imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $ink);
        if ($border < min($w, $h) / 2) {
            imagefilledrectangle($img, $x + $border, $y + $border, $x + $w - $border, $y + $h - $border, $this->color($img, $fill));
        }
    }

    /** An army's solid cut-out standing at the bottom of a box; a monogram tile for clubs without one. */
    protected function cutout(GdImage $img, ?array $army, $team, int $x, int $y, int $w, int $h, bool $flip, bool $grey): void
    {
        $file = $army['solid_path'] ?? null;
        $art = $file && is_file($file) ? @imagecreatefromwebp($file) : null;
        if (!$art) {
            $words = preg_split('/\s+/', trim(preg_replace('/\b(FC|AFC)\b|[^\p{L}\s]/u', '', (string) $team?->name)));
            $letters = mb_strtoupper(count($words) > 1 ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1) : mb_substr($words[0] ?? '?', 0, 3));
            $size = (int) (min($w, $h) * 0.6);
            $this->comicPanel($img, $x + (int) (($w - $size) / 2), $y + (int) (($h - $size) / 2), $size, $size, self::C_INK, 0, 0);
            $font = (int) ($size * 0.32);
            $b = imagettfbbox($font, 0, $this->font('display'), $letters);
            $this->text($img, 'display', $font, self::C_SUN, $x + (int) (($w - ($b[2] - $b[0])) / 2), $y + (int) (($h + ($b[1] - $b[7])) / 2), $letters);

            return;
        }
        imagealphablending($art, false);
        imagesavealpha($art, true);
        if ($flip) {
            imageflip($art, IMG_FLIP_HORIZONTAL);
        }
        if ($grey) {
            imagefilter($art, IMG_FILTER_GRAYSCALE);
        }
        $dh = (int) ($h * 0.96);
        $dw = (int) (imagesx($art) * $dh / imagesy($art));
        if ($dw > $w) {
            $dw = $w;
            $dh = (int) (imagesy($art) * $dw / imagesx($art));
        }
        imagecopyresampled($img, $art, $x + (int) (($w - $dw) / 2), $y + $h - $dh, 0, 0, $dw, $dh, imagesx($art), imagesy($art));
        imagedestroy($art);
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
