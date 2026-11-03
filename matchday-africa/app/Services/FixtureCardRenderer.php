<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Support\Armies;
use Carbon\Carbon;
use GdImage;
use Illuminate\Support\Facades\File;

/**
 * A 16:9 pre-match card for X: both clubs' crest-free armies facing off under their colours,
 * the fixture and the kick-off in Lagos time. Same art rules and drawing kit as the story cards.
 */
class FixtureCardRenderer extends StoryCardRenderer
{
    public const SIZE = [1200, 675];

    /** Absolute path to the PNG, drawn on first request and cached until the fixture changes. */
    public function fixturePath(FootballMatch $match): string
    {
        $style = self::cartoon() ? 'cartoon' : 'classic';
        $key = substr(md5($style.'|'.$match->home_team_id.'|'.$match->away_team_id.'|'.$match->match_date?->format('Y-m-d H:i').'|'.$this->result($match)), 0, 10);
        $file = storage_path("app/fixture-cards/{$match->id}-{$key}.png");
        if (!is_file($file)) {
            File::ensureDirectoryExists(dirname($file));
            imagepng($style === 'cartoon' ? $this->drawCartoon($match) : $this->drawFixture($match), $file, 6);
        }

        return $file;
    }

    public function drawFixture(FootballMatch $match): GdImage
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        [$w, $h] = self::SIZE;
        $home = Armies::for($match->homeTeam);
        $away = Armies::for($match->awayTeam);

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagefill($img, 0, 0, $this->color($img, [7, 8, 7]));
        $this->glow($img, (int) ($w * 0.6), (int) ($h * 0.45), (int) ($w * 0.36), $home['glow'] ?? '#d2a052');
        $this->glow($img, (int) ($w * 0.88), (int) ($h * 0.45), (int) ($w * 0.36), $away['glow'] ?? '#8c6a33');
        $this->army($img, $home, $h * 0.98, $w * 0.6, $h, flip: true);
        $this->army($img, $away, $h * 0.98, $w * 0.88, $h);
        $this->fade($img, 0, 0, (int) ($w * 0.64), $h, 'right', 12, 127);

        $pad = 60;
        $textWidth = (int) ($w * 0.56);
        $this->text($img, 'mono', 20, self::MUTED, $pad, $pad + 10, $this->fit(mb_strtoupper((string) ($match->league?->name ?: 'Football')), 'mono', 20, $textWidth));

        $result = $this->result($match);
        $tag = $result ? 'FULL TIME' : 'MATCH PREVIEW';
        $box = imagettfbbox(22, 0, $this->font('mono'), $tag);
        imagesetthickness($img, 2);
        imagerectangle($img, $pad, $pad + 38, $pad + $box[2] - $box[0] + 36, $pad + 86, $this->color($img, self::GOLD));
        $this->text($img, 'mono', 22, self::GOLD, $pad + 18, $pad + 72, $tag);

        $band = 14;
        $this->kente($img, $h - $band, $w, $band, $home['colours'] ?? ['#d2a052', '#e8e1d3']);
        $y = $h - $band - 34;
        $this->text($img, 'mono', 16, self::MUTED, $pad, $y, $result ? 'MATCHDAY.AFRICA  ·  THE STORY IN 20 SECONDS' : 'MATCHDAY.AFRICA  ·  WHO WINS? CALL IT');

        $kickoff = Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone(config('buffer.x.timezone'));
        $y -= 50;
        $this->text($img, 'mono', 24, self::GOLD, $pad, $y, $result
            ? 'FINAL SCORE  ·  '.mb_strtoupper($kickoff->format('D j M'))
            : 'KICK-OFF '.$kickoff->format('H:i').' WAT  ·  '.mb_strtoupper($kickoff->format('D j M')));

        $y -= 52;
        $name = fn ($team) => mb_strtoupper(trim(preg_replace('/\s+FC$/i', '', (string) $team?->name)) ?: 'TBC');
        // Long names ("Brighton & Hove Albion") step down in size before they are shortened.
        $width = fn (string $text, int $size) => ($box = imagettfbbox($size, 0, $this->font('display'), $text))[2] - $box[0];
        foreach ([$name($match->awayTeam), $result ?? 'V', $name($match->homeTeam)] as $i => $row) {
            $size = $i === 1 ? ($result ? 44 : 34) : 60;
            while ($i !== 1 && $size > 38 && $width($row, $size) > $textWidth) {
                $size -= 2;
            }
            $this->text($img, 'display', $size, $i === 1 ? self::GOLD : self::PAPER, $pad, $y, $this->fit($row, 'display', $size, $textWidth));
            $y -= $i === 0 ? 92 : 58;
        }

        return $img;
    }

    /**
     * The card's address, versioned by the look and the result: browsers, the host and link
     * previews cache the image, so a new look or a final score needs a new URL.
     */
    public static function url(FootballMatch $match): string
    {
        return route('matches.card', ['match' => $match, 'v' => substr(md5(config('site.style').'|'.$match->status.'|'.$match->home_score.'|'.$match->away_score), 0, 8)]);
    }

    /**
     * The site-wide look decides the card (SITE_STYLE), not a visitor's preview cookie: the same
     * card goes to Buffer, link previews and the home page.
     */
    public static function cartoon(): bool
    {
        return config('site.style') === 'cartoon';
    }

    private const INK = [11, 11, 11];
    private const SUN = [255, 210, 63];
    private const RAY = [255, 196, 31];
    private const CREAM = [255, 248, 231];
    private const RED = [255, 75, 62];
    private const SOFT = [58, 51, 40];

    /**
     * The cartoon card: a sunburst, a cream comic panel with the fixture, and each club's army
     * in its mood (warriors before kick-off; victory and defeat at full time) in framed panels.
     * Clubs without an army get a monogram tile: these cards go to social posts, so no crests.
     */
    public function drawCartoon(FootballMatch $match): GdImage
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        [$w, $h] = self::SIZE;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagefill($img, 0, 0, $this->color($img, self::SUN));

        // Sunburst from behind the armies.
        [$cx, $cy] = [930, 330];
        $ray = $this->color($img, self::RAY);
        for ($i = 0; $i < 24; $i += 2) {
            $a1 = deg2rad($i * 15);
            $a2 = deg2rad(($i + 1) * 15);
            imagefilledpolygon($img, [$cx, $cy, (int) ($cx + cos($a1) * 2000), (int) ($cy + sin($a1) * 2000), (int) ($cx + cos($a2) * 2000), (int) ($cy + sin($a2) * 2000)], $ray);
        }

        $result = $this->result($match);
        $lead = $result ? (int) $match->home_score - (int) $match->away_score : 0;
        $mood = fn (int $l) => !$result ? 'warrior' : ($l > 0 ? 'victory' : ($l < 0 ? 'defeated' : 'warrior'));
        $this->moodPanel($img, $match->homeTeam, $mood($lead), 690, 52, 290);
        $this->moodPanel($img, $match->awayTeam, $mood(-$lead), 866, 322, 290);

        // The comic panel.
        [$px, $py, $pw, $ph] = [44, 44, 620, 575];
        $this->panel($img, $px, $py, $pw, $ph, self::CREAM, 6, 12);
        $pad = 40;
        $x = $px + $pad;
        $textWidth = $pw - 2 * $pad;
        $this->text($img, 'mono', 18, self::SOFT, $x, $py + 52, $this->fit(mb_strtoupper((string) ($match->league?->name ?: 'Football')), 'mono', 18, $textWidth));

        $tag = $result ? 'FULL TIME' : 'MATCH PREVIEW';
        $box = imagettfbbox(24, 0, $this->font('display'), $tag);
        $this->panel($img, $x, $py + 72, $box[2] - $box[0] + 32, 52, self::RED, 4, 5);
        $this->text($img, 'display', 24, [255, 255, 255], $x + 16, $py + 112, $tag);

        $name = fn ($team) => mb_strtoupper(trim(preg_replace('/\s+(FC|AFC)$/i', '', (string) $team?->name)) ?: 'TBC');
        $width = fn (string $text, int $size) => ($b = imagettfbbox($size, 0, $this->font('display'), $text))[2] - $b[0];
        $sized = function (string $text) use ($width, $textWidth) {
            $size = 54;
            while ($size > 34 && $width($text, $size) > $textWidth) {
                $size -= 2;
            }

            return [$this->fit($text, 'display', $size, $textWidth), $size];
        };

        $y = $py + 220;
        [$homeName, $size] = $sized($name($match->homeTeam));
        $this->text($img, 'display', $size, self::INK, $x, $y, $homeName);
        $middle = $result ?? 'V';
        $mw = $width($middle, 34) + 30;
        $this->panel($img, $x, $y + 22, $mw, 62, self::SUN, 4, 0);
        $this->text($img, 'display', 34, self::INK, $x + 15, $y + 70, $middle);
        [$awayName, $size] = $sized($name($match->awayTeam));
        $this->text($img, 'display', $size, self::INK, $x, $y + 168, $awayName);

        $kickoff = Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone(config('buffer.x.timezone'));
        $line = $result ? 'FINAL SCORE · '.mb_strtoupper($kickoff->format('D j M')) : 'KICK-OFF '.$kickoff->format('H:i').' WAT · '.mb_strtoupper($kickoff->format('D j M'));
        $this->text($img, 'mono', 20, self::INK, $x, $py + $ph - 82, $this->fit($line, 'mono', 20, $textWidth));
        imagefilledrectangle($img, $x, $py + $ph - 64, $x + $textWidth, $py + $ph - 61, $this->color($img, self::INK));
        $this->text($img, 'mono', 16, self::SOFT, $x, $py + $ph - 30, $result ? 'MATCHDAY.AFRICA  ·  THE STORY IN 20 SECONDS' : 'MATCHDAY.AFRICA  ·  WHO WINS? CALL IT');

        return $img;
    }

    /** A filled box with an ink border and an offset ink shadow. */
    private function panel(GdImage $img, int $x, int $y, int $w, int $h, array $fill, int $border, int $shadow): void
    {
        $ink = $this->color($img, self::INK);
        if ($shadow) {
            imagefilledrectangle($img, $x + $shadow, $y + $shadow, $x + $w + $shadow, $y + $h + $shadow, $ink);
        }
        imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $ink);
        imagefilledrectangle($img, $x + $border, $y + $border, $x + $w - $border, $y + $h - $border, $this->color($img, $fill));
    }

    /** A club's army in its mood, framed; a monogram tile when the club has no army. */
    private function moodPanel(GdImage $img, $team, string $mood, int $x, int $y, int $size): void
    {
        $army = Armies::for($team);
        $file = $army && $army['art'] ? public_path(config('armies.optimised')."/moods/{$army['art']}-{$mood}.webp") : null;
        $this->panel($img, $x, $y, $size, $size, self::INK, 6, 12);
        if ($file && is_file($file) && ($art = @imagecreatefromwebp($file))) {
            imagecopyresampled($img, $art, $x + 6, $y + 6, 0, 0, $size - 12, $size - 12, imagesx($art), imagesy($art));

            return;
        }
        $words = preg_split('/\s+/', trim(preg_replace('/\b(FC|AFC|SK|CF|SC|AC|CA|RC|FK|US|ACF|VfB|SL|SV|SS|AS|SSC|OGC|RCD|UD|CD|SD|AFC)\b|[^\p{L}\s]/u', '', (string) $team?->name)));
        $letters = mb_strtoupper(count($words) > 1 ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1) : mb_substr($words[0] ?? '', 0, 3)) ?: '?';
        $fontSize = mb_strlen($letters) > 2 ? 78 : 104;
        $box = imagettfbbox($fontSize, 0, $this->font('display'), $letters);
        $this->text($img, 'display', $fontSize, self::SUN, $x + (int) (($size - ($box[2] - $box[0])) / 2), $y + (int) (($size + ($box[1] - $box[7])) / 2), $letters);
    }

    /** "2–1" once the match is finished, so the shared card shows the result; null before. */
    private function result(FootballMatch $match): ?string
    {
        return in_array($match->status, ['FINISHED', 'FT'], true) && $match->home_score !== null && $match->away_score !== null
            ? $match->home_score.'–'.$match->away_score
            : null;
    }
}
