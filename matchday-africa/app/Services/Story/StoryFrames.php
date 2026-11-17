<?php

namespace App\Services\Story;

use App\Design\Components;
use App\Design\Film;

/**
 * Match-story and radio-video frames: the story planner's beats mapped onto the shared component
 * library and drawn by the shared frame engine (App\Design\Film), so stories, radio videos and
 * slideshows look and move the same way. Colours, fonts, sizes, the safe zone and timings all
 * come from resources/design/tokens.json; nothing is drawn here.
 */
class StoryFrames extends Film
{
    /** Beat type => component. */
    public const COMPONENTS = [
        'hook' => 'hook', 'buildup' => 'matchup', 'goal' => 'goal', 'red' => 'red_card', 'stats' => 'stat_bars',
        'player' => 'profile', 'h2h' => 'head_to_head', 'form' => 'form_chips', 'table' => 'mini_table', 'next' => 'fixtures',
        'banter' => 'banter', 'close' => 'closing', 'recap' => 'score_block', 'facts' => 'fact_list', 'radio' => 'radio_cover',
    ];

    public function __construct(Components $kit)
    {
        parent::__construct($kit);
    }

    protected function component(array $cut): string
    {
        return self::COMPONENTS[$cut['type'] ?? ''] ?? (string) ($cut['component'] ?? 'fact_list');
    }

    /** The side a hook leans to: the featured player's, else the winner's. */
    private function focus(array $b): string
    {
        if (!empty($b['player'])) {
            return ($b['player']['club'] ?? null) === ($b['sides']['away']['name'] ?? false) ? 'away' : 'home';
        }
        [$h, $a] = array_map('intval', explode('-', (string) $b['score']) + [0, 0]);

        return $a > $h ? 'away' : 'home';
    }

    protected function data(array $b): array
    {
        $t = StoryText::for($b['lang'] ?? 'en');
        $sides = $b['sides'] ?? null;
        $common = ['sides' => $sides];

        return $common + match ($b['type']) {
            'hook' => ['line' => (string) $b['line'], 'score' => (string) $b['score'], 'player' => $b['player'] ?? null, 'photo' => $b['photo'] ?? null,
                'focus' => $this->focus($b)],
            'buildup' => ['title' => $b['title'], 'competition' => $b['competition'], 'stage' => $b['stage'] ?? null, 'lines' => $b['lines'] ?? []],
            'goal' => [
                'label' => $sides[$b['side']]['name'] ?? $t->t('goal'), 'minute' => $b['minute_label'] ?? null,
                'shout' => ($b['own_goal'] ? $t->t('own_goal') : ($b['penalty'] ? $t->t('penalty') : $t->t('goal'))).'!',
                'player' => (string) ($b['player'] ?: $sides[$b['side']]['name']),
                'line' => $b['own_goal'] ? $t->t('own_goal_for', ['name' => (string) $b['player'], 'club' => $sides[$b['side']]['name']]) : $sides[$b['side']]['full'],
                'assist' => $b['assist'] && !$b['own_goal'] ? $t->t('assist', ['name' => $b['assist']]) : null,
                'side' => $b['side'], 'before' => $b['before'], 'after' => $b['after'],
            ],
            'red' => ['label' => $t->t('red_card'), 'minute' => $b['minute_label'] ?? null, 'player' => (string) ($b['player'] ?: $sides[$b['side']]['name']),
                'side' => $b['side'], 'score' => $b['after']],
            'stats' => ['title' => $b['title'], 'rows' => $b['rows']],
            'player' => ['name' => $b['name'], 'label' => $b['label'] ?? null, 'club' => $b['club'] ?? null, 'lines' => $b['lines'] ?? [],
                'photo' => $b['photo'] ?? null, 'flag' => $b['flag'] ?? null,
                'colours' => ($b['club'] ?? null) === ($sides['away']['name'] ?? false) ? $sides['away']['colours'] : $sides['home']['colours']],
            'h2h' => ['title' => $b['title'], 'sub' => $b['sub'] ?? null, 'counts' => $b['counts'], 'lines' => $b['lines'] ?? []],
            'form' => ['title' => $b['title'], 'form' => $b['form']],
            'table' => ['title' => $b['title'], 'rows' => $b['rows'], 'played_label' => $t->t('played_short'), 'points_label' => ucfirst($t->t('pts'))],
            'next' => ['title' => $b['title'], 'lines' => $b['lines']],
            'banter' => ['title' => $b['title'], 'line' => $b['line'], 'score' => $b['score'] ?? null],
            'close' => ['title' => $t->t('question'), 'question' => $b['question'], 'cta' => $b['cta'], 'follow' => $b['follow'], 'url' => $b['url']],
            'recap' => ['title' => $b['title'], 'score' => (string) $b['score'], 'lines' => $b['lines'] ?? []],
            'facts' => ['title' => $b['title'], 'lines' => $b['lines'] ?? []],
            'radio' => ['label' => $t->t('radio'), 'edition' => $b['edition'] ?? null, 'title' => $b['title'] ?? '', 'caption' => $b['caption'] ?? null,
                'photo' => $b['photo'] ?? null, 'art' => $b['art'] ?? null, 'story' => $b['story'] ?? null, 'seconds' => $b['seconds'] ?? 3.0, 'at' => $b['start'] ?? 0],
            default => ['title' => (string) ($b['title'] ?? ''), 'lines' => (array) ($b['lines'] ?? [])],
        };
    }
}
