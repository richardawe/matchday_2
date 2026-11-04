<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Services\Griot;
use App\Support\Armies;
use GdImage;
use Illuminate\Support\Facades\File;

/**
 * WhatsApp-status banter cards (1080 x 1920) at full time: one per side, the winner's army in
 * victory under a sunburst, the loser's in the rain; on a draw both sides get the "nobody yields"
 * card. The Griot's words come from the score only (no player or fan insults), in its four voices.
 * Crest-free art only (AGENTS.md rule 6); clubs without an army get a monogram tile.
 */
class BanterCardRenderer extends FixtureCardRenderer
{
    public const SIZE = [1080, 1920];
    private const WHITE = [255, 255, 255];

    private const LINES = [
        'en' => [
            'lost' => ['eyebrow' => 'FOR THE {club} FAN IN YOUR GROUP', 'head' => '{club} FALL', 'line' => '{winner} beat {loser} {score}. Explain this one in the group chat.'],
            'won' => ['eyebrow' => 'FOR YOUR STATUS', 'head' => '{club} TAKE IT', 'line' => '{winner} beat {loser} {score}. Post it, let them see.'],
            'draw' => ['eyebrow' => 'FULL TIME', 'head' => 'NOBODY YIELDS', 'line' => '{home} {score} {away}. Both armies go home with a point.'],
        ],
        'pcm' => [
            'lost' => ['eyebrow' => 'FOR THE {club} FAN FOR YOUR GROUP', 'head' => '{club} DON FALL!', 'line' => '{winner} beat {loser} {score}. Una go explain this one for group chat.'],
            'won' => ['eyebrow' => 'FOR YOUR STATUS', 'head' => '{club} DON CARRY AM!', 'line' => '{winner} beat {loser} {score}. Post am make dem see.'],
            'draw' => ['eyebrow' => 'FULL TIME', 'head' => 'NOBODY GREE', 'line' => '{home} {score} {away}. Everybody collect one point go house.'],
        ],
        'sw' => [
            'lost' => ['eyebrow' => 'KWA SHABIKI WA {club} KWA GROUP', 'head' => '{club} WAMEANGUKA', 'line' => '{winner} wamewapiga {loser} {score}. Eleza hii kwa group.'],
            'won' => ['eyebrow' => 'KWA STATUS YAKO', 'head' => '{club} WAMEBEBA', 'line' => '{winner} wamewapiga {loser} {score}. Post hii waone.'],
            'draw' => ['eyebrow' => 'FULL TIME', 'head' => 'HAKUNA ALIYEKUBALI', 'line' => '{home} {score} {away}. Kila mtu pointi moja.'],
        ],
        'za' => [
            'lost' => ['eyebrow' => 'FOR THE {club} FAN IN YOUR GROUP', 'head' => 'EISH, {club} FALL', 'line' => '{winner} beat {loser} {score}. Explain that one in the group, neh.'],
            'won' => ['eyebrow' => 'FOR YOUR STATUS', 'head' => '{club} TAKE IT', 'line' => '{winner} beat {loser} {score}. Sharp sharp, post it and let them see.'],
            'draw' => ['eyebrow' => 'FULL TIME', 'head' => 'NOBODY BACKS DOWN', 'line' => '{home} {score} {away}. A point each, neh.'],
        ],
    ];

    public static function ready(FootballMatch $match): bool
    {
        return config('griot_challenge.banter') && $match->isFinished() && !$match->statusInferred();
    }

    public function cardPath(FootballMatch $match, string $side, string $voice): string
    {
        $key = substr(md5(self::version($match).'|'.$side.'|'.$voice), 0, 10);
        $file = storage_path("app/banter-cards/{$match->id}-{$side}-{$voice}-{$key}.png");
        if (!is_file($file)) {
            File::ensureDirectoryExists(dirname($file));
            imagepng($this->drawCard($match, $side, $voice), $file, 6);
        }

        return $file;
    }

    public static function cardUrl(FootballMatch $match, string $side, string $voice = 'pcm'): string
    {
        return route('matches.banter', ['match' => $match, 'side' => $side, 'voice' => $voice, 'v' => substr(md5(self::version($match)), 0, 8)]);
    }

    private static function version(FootballMatch $match): string
    {
        return 'b3|'.$match->home_team_id.'|'.$match->away_team_id.'|'.$match->home_score.'-'.$match->away_score;
    }

    /** 'won', 'lost' or 'draw' for that side. */
    public static function outcome(FootballMatch $match, string $side): string
    {
        $lead = (int) $match->home_score - (int) $match->away_score;
        if ($side === 'away') {
            $lead = -$lead;
        }

        return $lead > 0 ? 'won' : ($lead < 0 ? 'lost' : 'draw');
    }

    /** The Griot's words for the card (also the share text). @return array{eyebrow: string, head: string, line: string} */
    public function words(FootballMatch $match, string $side, string $voice): array
    {
        $voice = array_key_exists($voice, Griot::VOICES) ? $voice : 'en';
        $outcome = self::outcome($match, $side);
        $team = $side === 'away' ? $match->awayTeam : $match->homeTeam;
        $homeWon = (int) $match->home_score >= (int) $match->away_score;
        [$winner, $loser] = $homeWon ? [$match->homeTeam, $match->awayTeam] : [$match->awayTeam, $match->homeTeam];
        $high = max((int) $match->home_score, (int) $match->away_score);
        $low = min((int) $match->home_score, (int) $match->away_score);
        $values = [
            '{club}' => mb_strtoupper($this->club($team)),
            '{winner}' => $this->club($winner),
            '{loser}' => $this->club($loser),
            '{home}' => $this->club($match->homeTeam),
            '{away}' => $this->club($match->awayTeam),
            '{score}' => $outcome === 'draw' ? $match->home_score.'–'.$match->away_score : "{$high}–{$low}",
        ];

        return array_map(fn ($t) => strtr($t, $values), self::LINES[$voice][$outcome]);
    }

    public function drawCard(FootballMatch $match, string $side, string $voice): GdImage
    {
        $match->loadMissing(['homeTeam', 'awayTeam']);
        [$w, $h] = self::SIZE;
        $team = $side === 'away' ? $match->awayTeam : $match->homeTeam;
        $army = Armies::for($team);
        $outcome = self::outcome($match, $side);
        $ground = $this->rgb($army['colours'][0] ?? '#1c2c5b');
        // Very light kits (Fulham's white) would wash out the art: darken them.
        if (array_sum($ground) > 600) {
            $ground = [40, 40, 40];
        }

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagefill($img, 0, 0, $this->color($img, $ground));
        if ($outcome === 'won') {
            $ray = $this->color($img, [min(255, $ground[0] + 50), min(255, $ground[1] + 50), min(255, $ground[2] + 50)]);
            [$cx, $cy] = [540, 900];
            for ($i = 0; $i < 28; $i += 2) {
                $a1 = deg2rad($i * (360 / 28));
                $a2 = deg2rad(($i + 1) * (360 / 28));
                imagefilledpolygon($img, [$cx, $cy, (int) ($cx + cos($a1) * 2400), (int) ($cy + sin($a1) * 2400), (int) ($cx + cos($a2) * 2400), (int) ($cy + sin($a2) * 2400)], $ray);
            }
            imagefilledellipse($img, $cx, $cy, 640, 640, $this->color($img, self::SUN));
        } else {
            // Rain for the fallen, soft streaks for a draw.
            $rain = imagecolorallocatealpha($img, 255, 255, 255, $outcome === 'lost' ? 70 : 100);
            imagesetthickness($img, 5);
            mt_srand($match->id);
            for ($i = 0; $i < 70; $i++) {
                $x = mt_rand(0, $w);
                $y = mt_rand(300, 1400);
                imageline($img, $x, $y, $x - 18, $y + 90, $rain);
            }
            mt_srand();
        }

        // The army.
        $solid = $army['solid_path'] ?? null;
        if ($solid && is_file($solid) && ($art = @imagecreatefromwebp($solid))) {
            imagealphablending($art, false);
            imagesavealpha($art, true);
            if ($outcome === 'lost') {
                imagefilter($art, IMG_FILTER_BRIGHTNESS, -30);
            }
            $dh = 1000;
            $dw = (int) (imagesx($art) * $dh / imagesy($art));
            imagecopyresampled($img, $art, (int) (540 - $dw / 2), 1350 - $dh, 0, 0, $dw, $dh, imagesx($art), imagesy($art));
        } else {
            $this->moodPanel($img, $team, 'warrior', 240, 520, 600);
        }

        // Top: full-time tag, the monogram and the score.
        $this->panel($img, 60, 60, 300, 76, self::RED, 5, 8);
        $this->text($img, 'display', 40, self::WHITE, 84, 117, 'FULL TIME');
        $this->panel($img, $w - 156, 60, 96, 96, self::INK, 0, 0);
        $this->text($img, 'display', 46, self::SUN, $w - 142, 128, 'MD');
        $this->panel($img, 60, 180, $w - 120, 170, self::WHITE, 6, 10);
        $score = $match->home_score.'–'.$match->away_score;
        $sb = imagettfbbox(84, 0, $this->font('display'), $score);
        $sw = $sb[2] - $sb[0];
        $this->text($img, 'display', 84, self::INK, (int) (540 - $sw / 2), 310, $score);
        $sideWidth = (int) (($w - 120) / 2 - $sw / 2 - 60);
        // Full club names: both step down together until the longer one fits its half.
        [$homeName, $awayName] = [mb_strtoupper($this->club($match->homeTeam)), mb_strtoupper($this->club($match->awayTeam))];
        $nameSize = 44;
        $wide = fn (string $t, int $z) => ($b = imagettfbbox($z, 0, $this->font('display'), $t))[2] - $b[0];
        while ($nameSize > 26 && max($wide($homeName, $nameSize), $wide($awayName, $nameSize)) > $sideWidth) {
            $nameSize -= 2;
        }
        $home = $this->fit($homeName, 'display', $nameSize, $sideWidth);
        $this->text($img, 'display', $nameSize, self::INK, 90, 290, $home);
        $away = $this->fit($awayName, 'display', $nameSize, $sideWidth);
        $this->text($img, 'display', $nameSize, self::INK, $w - 90 - $wide($away, $nameSize), 290, $away);

        // Bottom: the Griot's words. The headline steps down until it fits two rows.
        $words = $this->words($match, $side, $voice);
        $panelY = 1330;
        $this->panel($img, 0, $panelY, $w, $h - $panelY, $outcome === 'won' ? self::WHITE : self::SUN, 8, 0);
        $x = 70;
        $width = $w - 140;
        $this->text($img, 'mono', 26, self::INK, $x, $panelY + 76, $this->fit($words['eyebrow'], 'mono', 26, $width));
        $size = 96;
        while ($size > 60 && count($this->wrap($words['head'], 'display', $size, $width)) > ($size > 80 ? 1 : 2)) {
            $size -= 6;
        }
        $y = $panelY + 104 + (int) ($size * 1.3);
        foreach (array_slice($this->wrap($words['head'], 'display', $size, $width), 0, 2) as $row) {
            $this->text($img, 'display', $size, self::INK, $x, $y, $row);
            $y += (int) ($size * 1.36);
        }
        $y += 6;
        foreach (array_slice($this->wrap($words['line'], 'body', 34, $width), 0, 2) as $row) {
            $this->text($img, 'body', 34, self::INK, $x, $y, $row);
            $y += 48;
        }
        imagefilledrectangle($img, $x, $h - 86, $x + $width, $h - 82, $this->color($img, self::INK));
        $this->text($img, 'mono', 26, self::INK, $x, $h - 36, 'THE GRIOT');
        $site = 'MATCHDAY.AFRICA';
        $tb = imagettfbbox(26, 0, $this->font('mono'), $site);
        $this->text($img, 'mono', 26, self::INK, $x + $width - ($tb[2] - $tb[0]), $h - 36, $site);

        return $img;
    }

    private function club($team): string
    {
        return \App\Support\ClubName::full($team);
    }
}
