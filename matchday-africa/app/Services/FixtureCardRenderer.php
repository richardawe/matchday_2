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
        $key = substr(md5($match->home_team_id.'|'.$match->away_team_id.'|'.$match->match_date?->format('Y-m-d H:i').'|'.$this->result($match)), 0, 10);
        $file = storage_path("app/fixture-cards/{$match->id}-{$key}.png");
        if (!is_file($file)) {
            File::ensureDirectoryExists(dirname($file));
            imagepng($this->drawFixture($match), $file, 6);
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

    /** "2–1" once the match is finished, so the shared card shows the result; null before. */
    private function result(FootballMatch $match): ?string
    {
        return in_array($match->status, ['FINISHED', 'FT'], true) && $match->home_score !== null && $match->away_score !== null
            ? $match->home_score.'–'.$match->away_score
            : null;
    }
}
