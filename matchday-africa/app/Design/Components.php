<?php

namespace App\Design;

/**
 * The component library: one implementation of each card, used by every video renderer (match
 * stories, radio videos, slideshows). Each method takes display-ready data (strings already in the
 * video's language, sides with names, flags and two colours) and returns a laid-out Scene.
 *
 * Layout follows the grid tokens: the scoreboard strip and the chips row sit at fixed places, the
 * photo area is the top 60% of the safe zone, and the body stack is stretched to fill the safe
 * zone (Scene::stack) so no card is more than tokens grid.max_empty bare backdrop.
 */
final class Components
{
    public const ALL = ['hook', 'matchup', 'score_block', 'goal', 'red_card', 'stat_bars', 'mini_table', 'form_chips', 'head_to_head',
        'profile', 'roster', 'fixtures', 'fact_list', 'banter', 'closing', 'radio_cover'];

    /** Builds the named component's scene from $d. */
    public function make(string $component, array $d): Scene
    {
        $method = lcfirst(str_replace('_', '', ucwords($component, '_')));
        if (!in_array($component, self::ALL, true) || !method_exists($this, $method)) {
            throw new DesignViolation("Unknown component: {$component}");
        }

        return $this->$method($d);
    }

    /** A card's identity: the same component with the same content twice in one video is a repeat. */
    public static function key(string $component, array $d): string
    {
        $content = collect($d)->except(['seconds', 'start', 'sides', 'lang', 'photo', 'art', 'keep', 'rank', 'padding', 'type'])->all();

        return $component.':'.substr(md5((string) json_encode($content)), 0, 12);
    }

    // ---------------------------------------------------------------- cards

    /** Full-bleed player photo, the scoreboard with both flags, the hook in yellow on black, the credit. Visible from frame 0. */
    public function hook(array $d): Scene
    {
        $sides = $this->sides($d);
        $focus = $d['focus'] ?? 'home';
        $s = new Scene('hook', self::key('hook', $d), 'black', $sides[$focus]['colours'][0]);
        [$w, $h] = [$s->w, $s->h];
        $photo = $d['photo'] ?? null;
        if (!$s->photo($photo['file'] ?? null, $photo['crop'] ?? null, 0, 0, $w, (int) Tokens::get('grid.caption.top'), $photo['credit'] ?? null)) {
            if (!empty($d['player'])) {
                $s->monogram((string) $d['player']['name'], $d['player']['flag'] ?? $sides[$focus]['flag'] ?? null,
                    0, 0, $w, (int) Tokens::get('grid.caption.top'), $sides[$focus]['colours']);
            } else {
                Canvas::versus($s->bg, $sides['home'], $sides['away'], 0, 0, $w, (int) Tokens::get('grid.caption.top'));
                $s->photos[] = ['file' => null, 'credit' => null, 'box' => [0, 0, $w, (int) Tokens::get('grid.caption.top')], 'own' => true];
            }
        }
        $s->scoreboard($sides, (string) ($d['score'] ?? 'v'), 0.0);
        // The caption area: a black panel from the caption top to the bottom of the frame.
        $capTop = (int) Tokens::get('grid.caption.top');
        $s->panel(0, $capTop - 8, $w, $h, 'black', 0.0, 'none', 0);
        $s->panel(0, $capTop - 8, $w, $capTop, $sides[$focus]['colours'][0], 0.0, 'none', 0);
        $width = $s->width();
        $who = $d['player']['name'] ?? null;
        $sub = $who ? implode(' · ', array_filter([$d['player']['label'] ?? null, $d['player']['club'] ?? null])) : null;
        $creditRoom = !empty($photo['credit']) ? 48 : 0;
        $room = $s->bottom - $creditRoom - ($capTop + 24) - ($who ? 84 : 0) - ($sub ? 66 : 0);
        $hook = Canvas::fitBox((string) $d['line'], 'hook', $width, $room, 3);
        $s->stack([
            ['h' => $s->linesHeight($hook, 'hook'), 'draw' => fn ($y) => $s->paragraph($hook, 'hook', $s->L, $y, 'yellow', 'black', 0.0, motion: 'punch', visible: true)],
            $who ? ['h' => 70, 'draw' => function ($y) use ($s, $who, $d) {
                $s->text($who, 'headline', $s->L, $y + 58, 'white', 'black', 0.2, maxWidth: $s->width() - (!empty($d['player']['flag']) ? 110 : 0),
                    px: 60, flag: $d['player']['flag'] ?? null);
            }] : null,
            $sub ? ['h' => 52, 'draw' => fn ($y) => $s->text($sub, 'body', $s->L, $y + 40, 'grey', 'black', 0.3, maxWidth: $s->width())] : null,
        ], $capTop + 24, $s->bottom - $creditRoom, 14);
        $s->credit($photo['credit'] ?? null);

        return $s;
    }

    /** What was at stake: the competition, both sides as colour bands, and the context lines. */
    public function matchup(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('matchup', self::key('matchup', $d), 'black', $sides['home']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $comp = Canvas::fit((string) $d['competition'], 'headline', $s->width(), 2);
        $lines = $this->bodyLines($d['lines'] ?? [], $s->width() - 64, 2);
        $s->stack([
            ['h' => $s->linesHeight($comp, 'headline') + (!empty($d['stage']) ? 56 : 0), 'draw' => function ($y) use ($s, $comp, $d) {
                $y = $s->paragraph($comp, 'headline', $s->L, $y, 'white', 'black', 0.1, kinetic: true);
                if (!empty($d['stage'])) {
                    $s->text((string) $d['stage'], 'body', $s->L, $y + 40, 'yellow', 'black', 0.3, maxWidth: $s->width());
                }
            }],
            ['h' => 170, 'flex' => 1, 'draw' => fn ($y, $h) => $this->sideBand($s, $sides['home'], $y, $h, 0.35)],
            ['h' => 170, 'flex' => 1, 'draw' => fn ($y, $h) => $this->sideBand($s, $sides['away'], $y, $h, 0.5)],
            $lines ? ['h' => $this->linesBlockHeight($s, $lines), 'flex' => 0.6, 'draw' => fn ($y, $h) => $this->linesPanel($s, $lines, $y, $h, 0.7)] : null,
        ], (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** The score on its own: label chip, the two-colour score block, the lines under it. */
    public function scoreBlock(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('score_block', self::key('score_block', $d), 'black', $sides['home']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $lines = $this->bodyLines($d['lines'] ?? [], $s->width() - 64, 2);
        $s->stack([
            ['h' => 120, 'draw' => fn ($y) => $this->namesRow($s, $sides, $y, 0.0)],
            ['h' => 340, 'flex' => 0.6, 'draw' => fn ($y, $h) => $s->scoreBlock($sides, (string) ($d['before'] ?? $d['score']), (string) $d['score'], $y, $h, 0.25)],
            $lines ? ['h' => $this->linesBlockHeight($s, $lines), 'flex' => 1, 'draw' => fn ($y, $h) => $this->linesPanel($s, $lines, $y, $h, 0.6)] : null,
        ], (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** A goal: flash, "Goal" punches in on the scoring side's colour, the scorer, then the score ticks over. */
    public function goal(array $d): Scene
    {
        $sides = $this->sides($d);
        $side = $sides[$d['side']];
        $s = new Scene('goal', self::key('goal', $d), 'black', $side['colours'][0]);
        $s->flash = true;
        $s->chip((string) $d['label'], $s->L);
        if (!empty($d['minute'])) {
            $s->minute((string) $d['minute']);
        }
        $ink = Canvas::ink($side['colours'][0]);
        $name = Canvas::fit((string) $d['player'], 'headline', $s->width() - 64, 2);
        $line = Canvas::fit((string) ($d['line'] ?? $side['full']), 'body', $s->width() - 64, 2);
        $assist = !empty($d['assist']) ? $this->bodyLines([$d['assist']], $s->width() - 88, 2) : [];
        $hookH = $s->linesHeight([[(string) $d['shout'], (int) Tokens::get('type.hook.size')]], 'hook');
        $s->stack([
            ['h' => $hookH + $s->linesHeight($name, 'headline') + $s->linesHeight($line, 'body') + 80, 'flex' => 1,
                'draw' => function ($y, $h) use ($s, $side, $ink, $name, $line, $d, $hookH) {
                    $s->panel($s->L, $y, $s->R, $y + $h, $side['colours'][0], 0.0, 'grow');
                    $inner = $hookH + $s->linesHeight($name, 'headline') + $s->linesHeight($line, 'body');
                    $yy = $y + intdiv($h - $inner, 2);
                    $s->text((string) $d['shout'], 'hook', $s->L + 32, $yy + $s->ascent('hook', (int) Tokens::get('type.hook.size')), $ink, 'team',
                        0.05, maxWidth: $s->width() - 64, motion: 'punch', teamHex: $side['colours'][0]);
                    $yy += $hookH;
                    $yy = $s->paragraph($name, 'headline', $s->L + 32, $yy, $ink, 'team', 0.35, kinetic: true, teamHex: $side['colours'][0]);
                    $s->paragraph($line, 'body', $s->L + 32, $yy, $ink, 'team', 0.6, teamHex: $side['colours'][0]);
                }],
            ['h' => 300, 'draw' => fn ($y, $h) => $s->scoreBlock($sides, (string) $d['before'], (string) $d['after'], $y, $h, 0.9)],
            $assist ? ['h' => $this->linesBlockHeight($s, $assist), 'draw' => fn ($y, $h) => $this->linesPanel($s, $assist, $y, $h, 1.1, 'yellow')] : null,
        ], (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** A red card: the card slides in, the player and side, the score as it stands. */
    public function redCard(array $d): Scene
    {
        $sides = $this->sides($d);
        $side = $sides[$d['side']];
        $s = new Scene('red_card', self::key('red_card', $d), 'black', Tokens::colour('red'));
        $s->chip((string) $d['label'], $s->L, fill: 'red');
        if (!empty($d['minute'])) {
            $s->minute((string) $d['minute']);
        }
        $name = Canvas::fit((string) $d['player'], 'headline', $s->width() - 300, 3);
        $s->stack([
            ['h' => max(340, $s->linesHeight($name, 'headline') + 120), 'flex' => 1, 'draw' => function ($y, $h) use ($s, $side, $name, $d) {
                $s->panel($s->L, $y, $s->R, $y + $h, 'surface', 0.0);
                $cardH = min($h - 80, 300);
                $cy = $y + intdiv($h - $cardH, 2);
                $s->panel($s->L + 40, $cy, $s->L + 40 + (int) round($cardH * 0.7), $cy + $cardH, 'red', 0.1, 'grow', 14);
                $tx = $s->L + 80 + (int) round($cardH * 0.7);
                $inner = $s->linesHeight($name, 'headline') + 56;
                $yy = $y + intdiv($h - $inner, 2);
                $yy = $s->paragraph($name, 'headline', $tx, $yy, 'white', 'surface', 0.3, kinetic: true);
                $s->text((string) $side['full'], 'body', $tx, $yy + 40, 'grey', 'surface', 0.55, maxWidth: $s->R - $tx - 20);
                $s->swatch($side, $s->R - 100, $y + 30, 72, 0.4);
            }],
            ['h' => 300, 'draw' => fn ($y, $h) => $s->scoreBlock($sides, (string) $d['score'], (string) $d['score'], $y, $h, 0.7)],
        ], (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** Key numbers, home on the left, away on the right, each row's bar split in the two colours. */
    public function statBars(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('stat_bars', self::key('stat_bars', $d), 'black', $sides['home']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $rows = array_slice((array) $d['rows'], 0, 5);
        $blocks = [['h' => 110, 'draw' => fn ($y) => $this->namesRow($s, $sides, $y, 0.05)]];
        foreach ($rows as $i => $row) {
            $blocks[] = ['h' => 150, 'flex' => 1, 'draw' => function ($y, $h) use ($s, $sides, $row, $i) {
                $delay = 0.25 + (float) Tokens::motion('item_stagger') * $i;
                $s->panel($s->L, $y, $s->R, $y + $h, 'surface', $delay);
                $suffix = !empty($row['percent']) ? '%' : '';
                $mid = $y + intdiv($h - 34, 2);
                $base = $mid + 20;
                $s->text($row['home'].$suffix, 'headline', $s->L + 28, $base, 'white', 'surface', $delay, px: 64);
                $s->text($row['away'].$suffix, 'headline', $s->R - 228, $base, 'white', 'surface', $delay, px: 64, align: 'right', box: [$s->R - 228, $s->R - 28]);
                $s->text((string) $row['label'], 'body', $s->L + 200, $base - 8, 'grey', 'surface', $delay, maxWidth: $s->width() - 420,
                    align: 'center', box: [$s->L + 200, $s->R - 200]);
                $total = max(1, (int) $row['home'] + (int) $row['away']);
                $split = (int) round($s->L + 28 + ($s->width() - 56) * (int) $row['home'] / $total);
                $s->bar($s->L + 28, $y + $h - 40, $s->R - 28, $y + $h - 22, $sides['home']['colours'][0], $sides['away']['colours'][0], $split, $delay + 0.1);
            }];
        }
        $s->stack($blocks, (int) Tokens::get('grid.body.top'), $s->bottom, 18);

        return $s;
    }

    /** A few table rows around both sides; the sides' rows in their colours. */
    public function miniTable(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('mini_table', self::key('mini_table', $d), 'black', $sides['away']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $rows = array_slice((array) $d['rows'], 0, 6);
        $blocks = [['h' => 50, 'draw' => function ($y) use ($s, $d) {
            if (!empty($d['played_label'])) {
                $s->text((string) $d['played_label'], 'label', $s->R - 250, $y + 36, 'grey', 'black', 0.1, align: 'right', box: [$s->R - 270, $s->R - 170]);
            }
            $s->text((string) ($d['points_label'] ?? 'Pts'), 'label', $s->R - 120, $y + 36, 'grey', 'black', 0.1, align: 'right', box: [$s->R - 140, $s->R - 28]);
        }]];
        foreach ($rows as $i => $row) {
            $blocks[] = ['h' => 110, 'flex' => 1, 'draw' => function ($y, $h) use ($s, $sides, $row, $i) {
                $delay = 0.15 + (float) Tokens::motion('item_stagger') * $i;
                $side = $row['side'] ?? null;
                $fill = $side ? $sides[$side]['colours'][0] : null;
                $on = $fill ? 'team' : 'surface';
                $ink = $fill ? Canvas::ink($fill) : 'white';
                $s->panel($s->L, $y, $s->R, $y + $h, $fill ?? 'surface', $delay);
                $base = $y + intdiv($h, 2) + 22;
                $s->text((string) $row['pos'], 'headline', $s->L + 28, $base + 4, $fill ? $ink : 'yellow', $on, $delay, px: 60, teamHex: $fill);
                $s->text((string) $row['name'], 'strong', $s->L + 130, $base, $ink, $on, $delay, maxWidth: $s->width() - 430, teamHex: $fill);
                if (($row['played'] ?? null) !== null) {
                    $s->text((string) $row['played'], 'strong', $s->R - 250, $base, $ink, $on, $delay, align: 'right', box: [$s->R - 270, $s->R - 170], teamHex: $fill);
                }
                if (($row['pts'] ?? null) !== null) {
                    $s->text((string) $row['pts'], 'headline', $s->R - 120, $base + 4, $ink, $on, $delay, px: 60, align: 'right', box: [$s->R - 140, $s->R - 28], teamHex: $fill);
                }
            }];
        }
        $s->stack($blocks, (int) Tokens::get('grid.body.top'), $s->bottom, 16);

        return $s;
    }

    /** Each side's last results as chips, oldest first: W yellow, D grey, L red. */
    public function formChips(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('form_chips', self::key('form_chips', $d), 'black', $sides['home']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $blocks = [];
        foreach (['home', 'away'] as $k => $key) {
            $form = array_slice(array_reverse((array) ($d['form'][$key] ?? [])), 0, 5);
            $blocks[] = ['h' => 330, 'flex' => 1, 'draw' => function ($y, $h) use ($s, $sides, $key, $form, $k) {
                $side = $sides[$key];
                $delay = 0.1 + 0.45 * $k;
                $s->panel($s->L, $y, $s->R, $y + $h, 'surface', $delay);
                $s->swatch($side, $s->L + 28, $y + 30, 64, $delay);
                $s->text((string) $side['full'], 'headline', $s->L + 116, $y + 88, 'white', 'surface', $delay, maxWidth: $s->width() - 150, px: 64);
                $cell = 148;
                $gap = intdiv($s->width() - 56 - 5 * $cell, 4);
                $cy = $y + 120 + intdiv($h - 120 - $cell - 50, 2);
                if (!$form) {
                    $s->text('No results on record', 'body', $s->L + 28, $cy + 80, 'grey', 'surface', $delay + 0.1);
                }
                foreach ($form as $i => $r) {
                    $x = $s->L + 28 + $i * ($cell + $gap);
                    $res = (string) $r['result'];
                    $fill = ['W' => 'yellow', 'D' => 'grey', 'L' => 'red'][$res] ?? 'grey';
                    $chipDelay = $delay + 0.15 + 0.07 * $i;
                    $s->panel($x, $cy, $x + $cell, $cy + $cell, $fill, $chipDelay, 'pop');
                    $s->text($res, 'headline', $x, $cy + 100, $fill === 'red' ? 'white' : 'black', $fill, $chipDelay,
                        align: 'center', box: [$x, $x + $cell], motion: 'pop');
                    $s->text((string) ($r['score'] ?? ''), 'label', $x, $cy + $cell + 40, 'grey', 'surface', $chipDelay, align: 'center', box: [$x, $x + $cell]);
                }
            }];
        }
        $s->stack($blocks, (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** Earlier meetings: wins, draws, wins as three counts, then the latest results. */
    public function headToHead(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('head_to_head', self::key('head_to_head', $d), 'black', $sides['away']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $lines = $this->bodyLines($d['lines'] ?? [], $s->width() - 64, 1);
        $s->stack([
            !empty($d['sub']) ? ['h' => 56, 'draw' => fn ($y) => $s->text((string) $d['sub'], 'body', $s->L, $y + 42, 'grey', 'black', 0.05, maxWidth: $s->width())] : null,
            ['h' => 380, 'flex' => 1, 'draw' => function ($y, $h) use ($s, $sides, $d) {
                $col = intdiv($s->width() - 32, 3);
                foreach (array_slice((array) $d['counts'], 0, 3) as $i => $c) {
                    $x = $s->L + $i * ($col + 16);
                    $fill = !empty($c['side']) ? $sides[$c['side']]['colours'][0] : null;
                    $on = $fill ? 'team' : 'surface';
                    $ink = $fill ? Canvas::ink($fill) : 'white';
                    $delay = 0.2 + (float) Tokens::motion('item_stagger') * $i;
                    $s->panel($x, $y, $x + $col, $y + $h, $fill ?? 'surface', $delay);
                    $s->text((string) $c['n'], 'score', $x, $y + intdiv($h, 2) + 40, $ink, $on, $delay, align: 'center', box: [$x, $x + $col], motion: 'punch', teamHex: $fill);
                    foreach (Canvas::fit((string) $c['label'], 'label', $col - 24, 2) as $j => [$part, $px]) {
                        $s->text($part, 'label', $x, $y + intdiv($h, 2) + 110 + $j * 36, $ink, $on, $delay + 0.1, px: $px, align: 'center', box: [$x, $x + $col], teamHex: $fill);
                    }
                }
            }],
            $lines ? ['h' => $this->linesBlockHeight($s, $lines), 'flex' => 0.4, 'draw' => fn ($y, $h) => $this->linesPanel($s, $lines, $y, $h, 0.7)] : null,
        ], (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** A player: licensed photo (or our monogram) in the photo area, name with flag, label, numbers, credit. */
    public function profile(array $d): Scene
    {
        $sides = $this->sides($d);
        $colours = $d['colours'] ?? $sides['home']['colours'];
        $s = new Scene('profile', self::key('profile', $d), 'black', $colours[0]);
        $photo = $d['photo'] ?? null;
        $top = (int) Tokens::get('grid.photo.top');
        $bottom = (int) Tokens::get('grid.photo.bottom');
        Canvas::rect($s->bg, $s->L - 12, $top - 12, $s->R + 12, $bottom + 12, $colours[0]);
        if (!$s->photo($photo['file'] ?? null, $photo['crop'] ?? null, $s->L, $top, $s->width(), $bottom - $top, $photo['credit'] ?? null)) {
            $s->monogram((string) $d['name'], $d['flag'] ?? null, $s->L, $top, $s->width(), $bottom - $top, $colours);
        }
        if (!empty($d['chip'])) {
            $s->chip((string) $d['chip'], $s->L + 24, $top + 24);
        }
        $name = Canvas::fit((string) $d['name'], 'headline', $s->width() - (!empty($d['flag']) ? 120 : 0), 2);
        $sub = implode(' · ', array_filter([$d['label'] ?? null, $d['club'] ?? null]));
        $lines = [];
        foreach ((array) ($d['lines'] ?? []) as $line) {
            $lines[] = ['head' => (string) ($line['head'] ?? ''), 'text' => Canvas::fit((string) ($line['text'] ?? ''), 'body', $s->width() - 64, 2)];
        }
        $credit = !empty($photo['credit']) ? 48 : 0;
        $s->stack([
            ['h' => $s->linesHeight($name, 'headline'), 'draw' => function ($y) use ($s, $name, $d) {
                foreach ($name as $i => [$line, $px]) {
                    $s->text($line, 'headline', $s->L, $y + $s->ascent('headline', $px), 'white', 'black', 0.1 + 0.1 * $i, px: $px,
                        flag: $i === 0 ? ($d['flag'] ?? null) : null);
                    $y += (int) round($px * (float) Tokens::get('type.headline.leading'));
                }
            }],
            $sub !== '' ? ['h' => 52, 'draw' => fn ($y) => $s->text($sub, 'body', $s->L, $y + 40, 'grey', 'black', 0.25, maxWidth: $s->width())] : null,
            $lines ? ['h' => 40 + array_sum(array_map(fn ($l) => 44 + $s->linesHeight($l['text'], 'body'), $lines)), 'flex' => 1,
                'draw' => function ($y, $h) use ($s, $lines) {
                    $s->panel($s->L, $y, $s->R, $y + $h, 'surface', 0.35);
                    $inner = array_sum(array_map(fn ($l) => 44 + $s->linesHeight($l['text'], 'body'), $lines));
                    $yy = $y + intdiv($h - $inner, 2);
                    foreach ($lines as $i => $l) {
                        if ($l['head'] !== '') {
                            $s->text($l['head'], 'label', $s->L + 32, $yy + 30, 'yellow', 'surface', 0.45 + 0.25 * $i);
                        }
                        $yy = $s->paragraph($l['text'], 'body', $s->L + 32, $yy + 44, 'white', 'surface', 0.5 + 0.25 * $i);
                    }
                }] : null,
        ], $bottom + 36, $s->bottom - $credit, 12);
        $s->credit($photo['credit'] ?? null);

        return $s;
    }

    /** Several players with a line each (slideshows: in-scope players, Team of the Week). */
    public function roster(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('roster', self::key('roster', $d), 'black', $sides['away']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $blocks = [];
        if (!empty($d['heading'])) {
            $heading = Canvas::fit((string) $d['heading'], 'headline', $s->width(), 2);
            $blocks[] = ['h' => $s->linesHeight($heading, 'headline'), 'draw' => fn ($y) => $s->paragraph($heading, 'headline', $s->L, $y, 'white', 'black', 0.05, kinetic: true)];
        }
        foreach (array_slice((array) $d['rows'], 0, 4) as $i => $row) {
            $stats = !empty($row['stats']) ? Canvas::fit((string) $row['stats'], 'body', $s->width() - 64, 2) : [];
            $blocks[] = ['h' => 110 + $s->linesHeight($stats, 'body'), 'flex' => 1, 'draw' => function ($y, $h) use ($s, $sides, $row, $stats, $i) {
                $delay = 0.2 + (float) Tokens::motion('item_stagger') * $i;
                $edge = $sides[$row['side'] ?? 'home']['colours'][0];
                $s->panel($s->L, $y, $s->R, $y + $h, 'surface', $delay);
                $s->panel($s->L, $y, $s->L + 14, $y + $h, $edge, $delay, 'none', 0);
                $inner = 110 + $s->linesHeight($stats, 'body');
                $yy = $y + intdiv($h - $inner, 2);
                $s->text((string) $row['name'], 'strong', $s->L + 40, $yy + 46, 'white', 'surface', $delay, maxWidth: $s->width() - 80);
                if (!empty($row['line'])) {
                    $s->text((string) $row['line'], 'label', $s->L + 40, $yy + 92, 'grey', 'surface', $delay, maxWidth: $s->width() - 80);
                }
                $s->paragraph($stats, 'body', $s->L + 40, $yy + 104, 'yellow', 'surface', $delay + 0.05);
            }];
        }
        $s->stack($blocks, (int) Tokens::get('grid.body.top'), $s->bottom, 18);

        return $s;
    }

    /** What's next for each side: the fixture and when. */
    public function fixtures(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('fixtures', self::key('fixtures', $d), 'black', $sides['home']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $blocks = [];
        foreach (array_slice((array) $d['lines'], 0, 3) as $i => $line) {
            $text = Canvas::fit((string) $line['text'], 'headline', $s->width() - 80, 2);
            $when = Canvas::fit((string) $line['when'], 'body', $s->width() - 80, 2);
            $blocks[] = ['h' => $s->linesHeight($text, 'headline') + $s->linesHeight($when, 'body') + 80, 'flex' => 1,
                'draw' => function ($y, $h) use ($s, $sides, $line, $text, $when, $i) {
                    $delay = 0.15 + 0.35 * $i;
                    $s->panel($s->L, $y, $s->R, $y + $h, 'surface', $delay);
                    $s->panel($s->L, $y, $s->L + 18, $y + $h, $sides[$line['side'] ?? 'home']['colours'][0], $delay, 'none', 0);
                    $inner = $s->linesHeight($text, 'headline') + $s->linesHeight($when, 'body') + 10;
                    $yy = $y + intdiv($h - $inner, 2);
                    $yy = $s->paragraph($text, 'headline', $s->L + 48, $yy, 'white', 'surface', $delay + 0.05, kinetic: true);
                    $s->paragraph($when, 'body', $s->L + 48, $yy + 10, 'yellow', 'surface', $delay + 0.3);
                }];
        }
        $s->stack($blocks, (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** A titled list of facts (match facts, kick-off times, venue). */
    public function factList(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('fact_list', self::key('fact_list', $d), 'black', $sides['away']['colours'][0]);
        $s->chip((string) $d['title'], $s->L);
        $blocks = [['h' => 120, 'draw' => fn ($y) => $this->namesRow($s, $sides, $y, 0.0)]];
        foreach (array_slice((array) ($d['lines'] ?? []), 0, 5) as $i => $line) {
            $fitted = Canvas::fit((string) $line, 'strong', $s->width() - 80, 2);
            $blocks[] = ['h' => $s->linesHeight($fitted, 'strong') + 50, 'flex' => 1, 'draw' => function ($y, $h) use ($s, $fitted, $i) {
                $delay = 0.15 + (float) Tokens::motion('item_stagger') * $i;
                $s->panel($s->L, $y, $s->R, $y + $h, 'surface', $delay);
                $s->panel($s->L, $y, $s->L + 14, $y + $h, 'yellow', $delay, 'none', 0);
                $s->paragraph($fitted, 'strong', $s->L + 44, $y + intdiv($h - $s->linesHeight($fitted, 'strong'), 2), 'white', 'surface', $delay + 0.05);
            }];
        }
        $s->stack($blocks, (int) Tokens::get('grid.body.top'), $s->bottom, 18);

        return $s;
    }

    /** The banter card: yellow, black words one by one, the sides' colours as bands underneath. */
    public function banter(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('banter', self::key('banter', $d), 'yellow', Tokens::colour('black'));
        $s->scoreboard($sides, (string) ($d['score'] ?? 'v'), 0.0, 'black');
        $s->chip((string) $d['title'], $s->L, fill: 'black');
        $line = Canvas::fit((string) $d['line'], 'hook', $s->width(), 6);
        $s->stack([
            ['h' => $s->linesHeight($line, 'hook'), 'draw' => fn ($y) => $s->paragraph($line, 'hook', $s->L, $y, 'black', 'yellow', 0.1, kinetic: true)],
            ['h' => 160, 'flex' => 1, 'draw' => function ($y, $h) use ($s, $sides) {
                $half = intdiv($h - 16, 2);
                $s->panel($s->L, $y, $s->R, $y + $half, $sides['home']['colours'][0], 0.5, 'grow', 0);
                $s->panel($s->L, $y + $half + 16, $s->R, $y + $h, $sides['away']['colours'][0], 0.65, 'grow', 0);
                $s->swatch($sides['home'], $s->R - 100, $y + intdiv($half - 64, 2), 64, 0.6);
                $s->swatch($sides['away'], $s->R - 100, $y + $half + 16 + intdiv($half - 64, 2), 64, 0.75);
                $hp = Canvas::shrink($sides['home']['full'], Tokens::type('headline')['file'], 64, 40, $s->width() - 180);
                $s->text($sides['home']['full'], 'headline', $s->L + 32, $y + intdiv($half, 2) + 26, Canvas::ink($sides['home']['colours'][0]), 'team', 0.55,
                    px: $hp, teamHex: $sides['home']['colours'][0]);
                $ap = Canvas::shrink($sides['away']['full'], Tokens::type('headline')['file'], 64, 40, $s->width() - 180);
                $s->text($sides['away']['full'], 'headline', $s->L + 32, $y + $half + 16 + intdiv($half, 2) + 26, Canvas::ink($sides['away']['colours'][0]), 'team', 0.7,
                    px: $ap, teamHex: $sides['away']['colours'][0]);
            }],
        ], (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /** The closing card: the question word by word, the comment ask, the follow ask, the logo and matchday.africa. */
    public function closing(array $d): Scene
    {
        $sides = $this->sides($d);
        $s = new Scene('closing', self::key('closing', $d), 'black', Tokens::colour('yellow'));
        $s->chip((string) $d['title'], $s->L);
        $q = Canvas::fit((string) $d['question'], 'headline', $s->width() - 64, 5);
        $cta = !empty($d['cta']) ? Canvas::fit((string) $d['cta'], 'body', $s->width() - 64, 2) : [];
        $s->stack([
            ['h' => $s->linesHeight($q, 'headline') + $s->linesHeight($cta, 'body') + 90, 'flex' => 1, 'draw' => function ($y, $h) use ($s, $q, $cta) {
                $s->panel($s->L, $y, $s->R, $y + $h, 'surface', 0.0);
                $inner = $s->linesHeight($q, 'headline') + $s->linesHeight($cta, 'body') + 20;
                $yy = $y + intdiv($h - $inner, 2);
                $yy = $s->paragraph($q, 'headline', $s->L + 32, $yy, 'white', 'surface', 0.1, kinetic: true);
                $s->paragraph($cta, 'body', $s->L + 32, $yy + 20, 'grey', 'surface', 0.8);
            }],
            ['h' => 150, 'draw' => function ($y, $h) use ($s, $d) {
                $s->panel($s->L, $y, $s->R, $y + $h, 'yellow', 1.0, 'pop');
                $s->text((string) $d['follow'], 'headline', $s->L, $y + intdiv($h, 2) + 28, 'black', 'yellow', 1.0, maxWidth: $s->width() - 64,
                    align: 'center', box: [$s->L, $s->R], motion: 'punch');
            }],
            ['h' => 150, 'draw' => function ($y, $h) use ($s, $d) {
                $s->panel($s->L, $y, $s->R, $y + $h, 'surface', 1.2);
                $mark = 110;
                $s->items[] = ['kind' => 'logo', 'text' => '', 'x' => $s->L + 24, 'y' => $y + intdiv($h - $mark, 2), 'size' => $mark,
                    'delay' => 1.2, 'fade' => (float) Tokens::motion('in'), 'motion' => 'fade', 'animate' => true, 'top' => $y, 'bottom' => $y + $h, 'width' => $mark, 'flag' => null];
                $s->text((string) ($d['url'] ?? 'matchday.africa'), 'strip', $s->L + 160, $y + intdiv($h, 2) + 22, 'yellow', 'surface', 1.25,
                    maxWidth: $s->width() - 190);
            }],
        ], (int) Tokens::get('grid.body.top'), $s->bottom);

        return $s;
    }

    /**
     * A radio video cut: the Matchday Radio chip and edition, the story's licensed photo or our own art,
     * the headline, and the words being spoken appearing one by one on a white caption panel.
     */
    public function radioCover(array $d): Scene
    {
        $s = new Scene('radio_cover', self::key('radio_cover', $d), 'black', Tokens::colour('yellow'));
        $chipY = (int) Tokens::get('grid.scoreboard.top');
        $right = $s->chip((string) ($d['label'] ?? 'Matchday Radio'), $s->L, $chipY, 'yellow', 0.0);
        // The brand chip is on screen from frame 0 and stays put while the words change.
        foreach ([array_key_last($s->items), array_key_last($s->items) - 1] as $k) {
            $s->items[$k]['motion'] = 'none';
            $s->items[$k]['animate'] = false;
        }
        if (!empty($d['edition'])) {
            $s->text((string) $d['edition'], 'label', $right + 20, $chipY + 42, 'grey', 'black', 0.0, maxWidth: $s->R - $right - 20, motion: 'none');
        }
        $photo = $d['photo'] ?? null;
        $top = $chipY + (int) Tokens::get('grid.chips.height') + 24;
        $bottom = (int) Tokens::get('grid.photo.bottom');
        $story = !empty($d['story']);
        if ($photo && !empty($photo['file']) && $s->photo($photo['file'], $photo['crop'] ?? null, $s->L, $top, $s->width(), $bottom - $top, $photo['credit'] ?? null)) {
            Canvas::rect($s->bg, $s->L - 10, $top - 10, $s->R + 10, $bottom + 10, 'yellow');
        } elseif ($story) {
            // A news segment without a licensed photo: our own headline card, so each story looks different.
            Canvas::rect($s->bg, $s->L, $top, $s->R, $bottom, 'yellow');
            $s->chip((string) $d['story'], $s->L + 32, $top + 32, 'black', 0.0);
            $head = Canvas::fitBox((string) ($d['title'] ?? ''), 'hook', $s->width() - 64, $bottom - $top - 160, 5);
            $s->paragraph($head, 'hook', $s->L + 32, $top + 128, 'black', 'yellow', 0.0, motion: 'none');
        } else {
            Canvas::rect($s->bg, $s->L - 10, $top - 10, $s->R + 10, $bottom + 10, 'yellow');
            if (!$s->photo($d['art'] ?? null, null, $s->L, $top, $s->width(), $bottom - $top, null, contain: true)) {
                $s->monogram('Matchday Radio', null, $s->L, $top, $s->width(), $bottom - $top, [Tokens::colour('black'), Tokens::colour('yellow')]);
            }
        }
        $capTop = $bottom + 36;
        if (!$story) {
            $title = Canvas::fit((string) ($d['title'] ?? ''), 'headline', $s->width(), 2);
            $s->paragraph($title, 'headline', $s->L, $capTop, 'white', 'black', 0.0, motion: 'none');
            $capTop += $s->linesHeight($title, 'headline') + 24;
        }
        $credit = !empty($photo['credit']) ? 48 : 0;
        $panelBottom = $s->bottom - $credit;
        $s->panel($s->L - 10, $capTop, $s->R + 10, $panelBottom, 'white', 0.0, 'none');
        if (!empty($d['caption'])) {
            // Word by word: each word appears as it is (roughly) spoken across the cue.
            $lines = Canvas::fitBox((string) $d['caption'], 'caption', $s->width() - 50, $panelBottom - $capTop - 40, 6);
            $count = max(1, array_sum(array_map(fn ($l) => count(preg_split('/\s+/u', $l[0])), $lines)));
            $per = max(0.05, ((float) ($d['seconds'] ?? 3.0) * 0.85) / $count);
            $type = Tokens::type('caption');
            CopyRules::assert((string) $d['caption'], 'radio caption');
            $y = $capTop + intdiv($panelBottom - $capTop - $s->linesHeight($lines, 'caption'), 2);
            $k = 0;
            foreach ($lines as [$line, $px]) {
                $base = $y + $s->ascent('caption', $px);
                $x = $s->L + 15;
                foreach (preg_split('/\s+/u', $line) as $word) {
                    $item = $s->text($word, 'caption', $x, $base, 'black', 'white', $k * $per, px: $px);
                    $s->items[array_key_last($s->items)]['fade'] = 0.12;
                    $x += $item['width'] + (int) round($px * 0.28);
                    $k++;
                }
                $y += (int) round($px * (float) $type['leading']);
            }
        }
        $s->credit($photo['credit'] ?? null);
        $s->still = (bool) ($d['still'] ?? false);

        return $s;
    }

    // ---------------------------------------------------------------- shared pieces

    /** Both sides with safe colours; a card without sides (radio) gets the brand pair. */
    private function sides(array $d): array
    {
        $sides = $d['sides'] ?? null;
        $out = [];
        foreach (['home', 'away'] as $k) {
            $side = (array) ($sides[$k] ?? []);
            $out[$k] = [
                'name' => (string) ($side['name'] ?? ''), 'full' => (string) ($side['full'] ?? $side['name'] ?? ''),
                'flag' => $side['flag'] ?? null, 'colours' => Canvas::teamColours($side['colours'] ?? null, $k),
            ];
        }
        if (strcasecmp($out['home']['colours'][0], $out['away']['colours'][0]) === 0) {
            $out['away']['colours'] = [$out['away']['colours'][1], $out['away']['colours'][0]];
        }

        return $out;
    }

    /** A side as a colour band with its full name and its flag or swatch. */
    private function sideBand(Scene $s, array $side, int $y, int $h, float $delay): void
    {
        $fill = $side['colours'][0];
        $s->panel($s->L, $y, $s->R, $y + $h, $fill, $delay, 'grow');
        $s->panel($s->R - 24, $y, $s->R, $y + $h, $side['colours'][1], $delay, 'none', 0);
        $px = Canvas::shrink($side['full'], Tokens::type('headline')['file'], (int) Tokens::get('type.headline.size'), (int) Tokens::get('type.headline.min'), $s->width() - 220);
        $s->text($side['full'], 'headline', $s->L + 36, $y + intdiv($h, 2) + (int) round($px * 0.36), Canvas::ink($fill), 'team', $delay + 0.1, px: $px, teamHex: $fill);
        $s->swatch($side, $s->R - 140, $y + intdiv($h - 84, 2), 84, $delay + 0.15);
    }

    /** Both sides' swatches and short names on one row. */
    private function namesRow(Scene $s, array $sides, int $y, float $delay): void
    {
        $s->swatch($sides['home'], $s->L, $y + 14, 72, $delay);
        $s->swatch($sides['away'], $s->R - 72, $y + 14, 72, $delay);
        $half = intdiv($s->width(), 2) - 100;
        $s->text($sides['home']['name'], 'headline', $s->L + 92, $y + 76, 'white', 'black', $delay, maxWidth: $half, px: 60);
        $s->text($sides['away']['name'], 'headline', $s->R - 92 - $half, $y + 76, 'white', 'black', $delay, maxWidth: $half, px: 60,
            align: 'right', box: [$s->R - 92 - $half, $s->R - 92]);
    }

    /** Body lines fitted to $width: [[[line, px], ...], ...] one entry per source line. */
    private function bodyLines(array $lines, int $width, int $max): array
    {
        return array_values(array_filter(array_map(fn ($l) => Canvas::fit((string) $l, 'body', $width, $max), array_slice($lines, 0, 5))));
    }

    private function linesBlockHeight(Scene $s, array $groups): int
    {
        return 48 + array_sum(array_map(fn ($g) => $s->linesHeight($g, 'body') + 10, $groups));
    }

    /** A surface panel of body lines with a yellow edge, centred vertically. */
    private function linesPanel(Scene $s, array $groups, int $y, int $h, float $delay, string $tone = 'white'): void
    {
        $s->panel($s->L, $y, $s->R, $y + $h, 'surface', $delay);
        $s->panel($s->L, $y, $s->L + 14, $y + $h, 'yellow', $delay, 'none', 0);
        $inner = array_sum(array_map(fn ($g) => $s->linesHeight($g, 'body') + 10, $groups)) - 10;
        $yy = $y + intdiv($h - $inner, 2);
        foreach ($groups as $i => $g) {
            $yy = $s->paragraph($g, 'body', $s->L + 44, $yy, $tone, 'surface', $delay + 0.1 + (float) Tokens::motion('line_stagger') * $i) + 10;
        }
    }
}
