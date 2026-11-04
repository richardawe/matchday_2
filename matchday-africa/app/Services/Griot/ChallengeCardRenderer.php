<?php

namespace App\Services\Griot;

use App\Models\GriotChallenge;
use App\Services\FixtureCardRenderer;
use GdImage;
use Illuminate\Support\Facades\File;

/**
 * Square social cards for Beat the Griot, in the cartoon kit: "The Griot has spoken" (its three
 * calls) when a round opens, and the verdict (how many fans beat it) once it is scored. Only the
 * site's own facts: teams, kick-off days, the calls, the results and the counts.
 */
class ChallengeCardRenderer extends FixtureCardRenderer
{
    public const SQUARE = 1080;
    private const SKY = [79, 179, 255];
    private const WHITE = [255, 255, 255];

    public function cardPath(GriotChallenge $challenge, string $kind): string
    {
        $key = substr(md5(self::version($challenge, $kind)), 0, 10);
        $file = storage_path("app/griot-cards/{$challenge->id}-{$kind}-{$key}.png");
        if (!is_file($file)) {
            File::ensureDirectoryExists(dirname($file));
            imagepng($kind === 'verdict' ? $this->verdict($challenge) : $this->calls($challenge), $file, 6);
        }

        return $file;
    }

    public static function cardUrl(GriotChallenge $challenge, string $kind): string
    {
        return route('griot.card', ['challenge' => $challenge, 'kind' => $kind, 'v' => substr(md5(self::version($challenge, $kind)), 0, 8)]);
    }

    private static function version(GriotChallenge $challenge, string $kind): string
    {
        return 'v2|'.$kind.'|'.$challenge->status.'|'.$challenge->beaters.'|'.$challenge->griot_points.'|'.$challenge->picks->map(fn ($p) => $p->match_id.':'.$p->call().':'.$p->match?->home_score.'-'.$p->match?->away_score)->implode(',');
    }

    public function calls(GriotChallenge $challenge): GdImage
    {
        $img = $this->ground(self::SUN, self::RAY);
        $this->text($img, 'mono', 24, self::INK, 70, 110, 'BEAT THE GRIOT  ·  '.mb_strtoupper($challenge->datesLabel()));
        $this->text($img, 'display', 100, self::INK, 66, 250, 'THE GRIOT');
        $this->text($img, 'display', 100, self::INK, 66, 376, 'HAS SPOKEN');
        $y = 432;
        foreach ($challenge->picks as $pick) {
            $day = mb_strtoupper($this->kickoff($pick->match)->setTimezone('Africa/Lagos')->format('D'));
            $this->row($img, $y, $day, $this->club($pick->match->homeTeam), $pick->call(), $this->club($pick->match->awayTeam), self::WHITE);
            $y += 150;
        }
        $this->sticker($img, 70, 900, 'BEAT IT, WIN '.mb_strtoupper((string) config('griot_challenge.prize')));
        $this->text($img, 'mono', 24, self::INK, 70, 1030, 'MATCHDAY.AFRICA/GRIOT');

        return $img;
    }

    public function verdict(GriotChallenge $challenge): GdImage
    {
        $img = $this->ground(self::SKY, [120, 198, 255]);
        $this->text($img, 'mono', 24, self::INK, 70, 110, 'BEAT THE GRIOT  ·  THE VERDICT');
        [$top, $bottom] = $challenge->beaters > 0
            ? [number_format($challenge->beaters).' '.($challenge->beaters === 1 ? 'FAN' : 'FANS'), 'BEAT THE GRIOT']
            : ['THE GRIOT', 'WINS THIS ONE'];
        $this->text($img, 'display', 100, self::INK, 66, 250, $top);
        $this->text($img, 'display', 100, self::INK, 66, 376, $bottom);
        $y = 432;
        foreach ($challenge->picks as $pick) {
            $m = $pick->match;
            $done = $m && $m->isFinished();
            $score = $done ? $m->home_score.'–'.$m->away_score : 'VOID';
            $pts = $done ? GriotChallengeService::points($pick->home_pick, $pick->away_pick, (int) $m->home_score, (int) $m->away_score) : 0;
            $this->row($img, $y, 'FT', $this->club($m?->homeTeam), $score, $this->club($m?->awayTeam), self::WHITE, 'GRIOT '.$pick->call().' · +'.$pts);
            $y += 150;
        }
        $this->sticker($img, 70, 900, 'GRIOT: '.(int) $challenge->griot_points.' PTS');
        $this->text($img, 'mono', 24, self::INK, 70, 1030, 'NEXT THREE CALLS: MATCHDAY.AFRICA/GRIOT');

        return $img;
    }

    private function ground(array $fill, array $ray): GdImage
    {
        $s = self::SQUARE;
        $img = imagecreatetruecolor($s, $s);
        imagealphablending($img, true);
        imagefill($img, 0, 0, $this->color($img, $fill));
        $c = $this->color($img, $ray);
        [$cx, $cy] = [900, 200];
        for ($i = 0; $i < 24; $i += 2) {
            $a1 = deg2rad($i * 15);
            $a2 = deg2rad(($i + 1) * 15);
            imagefilledpolygon($img, [$cx, $cy, (int) ($cx + cos($a1) * 2000), (int) ($cy + sin($a1) * 2000), (int) ($cx + cos($a2) * 2000), (int) ($cy + sin($a2) * 2000)], $c);
        }
        $ink = $this->color($img, self::INK);
        imagesetthickness($img, 12);
        imagerectangle($img, 6, 6, $s - 7, $s - 7, $ink);

        return $img;
    }

    /** One fixture row: a day tag, home, the score box, away, and an optional note under it. */
    private function row(GdImage $img, int $y, string $tag, string $home, string $middle, string $away, array $fill, ?string $note = null): void
    {
        $x = 70;
        $w = self::SQUARE - 140;
        $h = $note ? 124 : 110;
        $this->panel($img, $x, $y, $w, $h, $fill, 5, 8);
        $this->text($img, 'mono', 22, self::INK, $x + 24, $y + 66, $tag);
        $box = imagettfbbox(52, 0, $this->font('display'), $middle);
        $bw = $box[2] - $box[0] + 40;
        $bx = (int) ($x + $w / 2 - $bw / 2 + 40);
        imagefilledrectangle($img, $bx, $y + 18, $bx + $bw, $y + 92, $this->color($img, self::INK));
        $this->text($img, 'display', 52, self::SUN, $bx + 20, $y + 80, $middle);
        $side = (int) ($bx - $x - 120);
        $this->text($img, 'display', 38, self::INK, $x + 100, $y + 72, $this->fit(mb_strtoupper($home), 'display', 38, $side));
        $awayText = $this->fit(mb_strtoupper($away), 'display', 38, $x + $w - ($bx + $bw) - 40);
        $ab = imagettfbbox(38, 0, $this->font('display'), $awayText);
        $this->text($img, 'display', 38, self::INK, $x + $w - 24 - ($ab[2] - $ab[0]), $y + 72, $awayText);
        if ($note) {
            $nb = imagettfbbox(18, 0, $this->font('mono'), $note);
            $this->text($img, 'mono', 18, self::SOFT, (int) ($bx + $bw / 2 - ($nb[2] - $nb[0]) / 2), $y + 116, $note);
        }
    }

    private function sticker(GdImage $img, int $x, int $y, string $text): void
    {
        $box = imagettfbbox(40, 0, $this->font('display'), $text);
        $this->panel($img, $x, $y, $box[2] - $box[0] + 48, 76, self::RED, 5, 8);
        $this->text($img, 'display', 40, self::WHITE, $x + 24, $y + 58, $text);
    }

    private function club($team): string
    {
        return \App\Support\ClubName::short($team);
    }

    private function kickoff($match): \Carbon\Carbon
    {
        return \Carbon\Carbon::parse($match->match_date->format('Y-m-d H:i:s'), 'UTC');
    }
}
