<?php

namespace App\Services\Story;

use App\Design\Tokens;
use App\Models\FootballMatch;
use App\Models\MatchEvent;
use App\Models\Player;
use App\Models\Standing;
use App\Services\Slideshow\CommonsPhotos;
use App\Services\Slideshow\SlideshowFacts;
use App\Support\AfricanScope;
use App\Support\ClubName;
use App\Support\PlayerNames;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns one finished match into the running order of a match-story video, from stored data only
 * (score, events, stats columns, standings, earlier results, fixtures, line-ups, licensed photos).
 * A fact that isn't stored is left out, never estimated. The beats always add up to 61-75 s:
 * cuts of 3-5 s, padded with verified context (head-to-head, form, player numbers, recaps).
 */
class StoryPlanner
{
    public function __construct(private CommonsPhotos $photos) {}

    /**
     * @return array{beats: list<array>, hook: string, banter: string, question: string, facts: array,
     *               player_id: ?int, photo: ?array, fingerprint: string}
     */
    public function plan(FootballMatch $match, string $lang = 'en', ?string $hook = null, ?string $banter = null): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        $t = StoryText::for($lang);
        $sides = $this->sides($match);
        $incidents = $this->incidents($match);
        $goals = $incidents->where('type', 'goal')->values();
        [$featured, $photo] = $this->featured($match);
        $player = $featured['player'] ?? null;
        $standings = ['home' => SlideshowFacts::standing($match->homeTeam, $match->league), 'away' => SlideshowFacts::standing($match->awayTeam, $match->league)];
        $score = "{$match->home_score}-{$match->away_score}";

        $generatedHook = $this->hook($match, $t, $sides, $incidents, $standings, $player);
        $generatedBanter = $this->banter($match, $t, $sides);
        $hookLine = trim((string) $hook) !== '' ? trim((string) $hook) : $generatedHook;
        $banterLine = trim((string) $banter) !== '' ? trim((string) $banter) : $generatedBanter;
        $question = $this->question($match, $t, $sides, $player, $featured);
        $url = 'matchday.africa/matches/'.$match->id;

        $beats = [];
        $beats[] = ['type' => 'hook', 'keep' => true, 'rank' => 100, 'line' => $hookLine, 'score' => $score,
            'player' => $player ? ['name' => $player->name, 'label' => AfricanScope::label($player), 'club' => ClubName::short($player->team),
                'flag' => AfricanScope::nation(AfricanScope::countryCode($player))['flag'] ?? null] : null,
            'photo' => $photo ? ['file' => $photo['file'], 'crop' => $photo['crop'] ?? null, 'credit' => $photo['credit'] ?? null] : null];
        $beats[] = ['type' => 'buildup', 'keep' => true, 'rank' => 90] + $this->buildup($match, $t, $sides, $standings);
        foreach ($incidents as $i => $incident) {
            $beats[] = ['type' => $incident['type'] === 'goal' ? 'goal' : 'red', 'keep' => true, 'rank' => 95] + $incident;
        }
        if ($stats = $this->stats($match, $t)) {
            $beats[] = ['type' => 'stats', 'keep' => false, 'rank' => 70, 'title' => $t->t('key_stats'), 'rows' => $stats];
        }
        if ($featured && ($lines = $this->playerLines($featured, $match, $t))) {
            $beats[] = ['type' => 'player', 'keep' => false, 'rank' => 75, 'name' => $player->name, 'label' => AfricanScope::label($player),
                'club' => ClubName::short($player->team), 'lines' => $lines,
                'photo' => $photo ? ['file' => $photo['file'], 'crop' => $photo['crop'] ?? null, 'credit' => $photo['credit'] ?? null] : null,
                'flag' => AfricanScope::nation(AfricanScope::countryCode($player))['flag'] ?? null];
        }
        if ($h2h = $this->headToHead($match, $t, $sides)) {
            $beats[] = ['type' => 'h2h', 'keep' => false, 'rank' => 40] + $h2h;
        }
        $form = ['home' => SlideshowFacts::form($match->homeTeam, $match), 'away' => SlideshowFacts::form($match->awayTeam, $match)];
        if ($form['home'] || $form['away']) {
            $beats[] = ['type' => 'form', 'keep' => false, 'rank' => 30, 'title' => $t->t('form'), 'form' => $form];
        }
        if ($table = $this->table($match, $t)) {
            $beats[] = ['type' => 'table', 'keep' => false, 'rank' => 60, 'title' => $t->t('table'), 'rows' => $table];
        }
        if ($next = $this->nextFixtures($match, $t)) {
            $beats[] = ['type' => 'next', 'keep' => false, 'rank' => 50, 'title' => $t->t('next_fixture'), 'lines' => $next];
        }
        $beats[] = ['type' => 'banter', 'keep' => true, 'rank' => 85, 'title' => $t->t('banter'), 'line' => $banterLine, 'score' => $score];
        $beats[] = ['type' => 'close', 'keep' => true, 'rank' => 100, 'question' => $question, 'cta' => $t->t('cta_comment'),
            'follow' => $t->t('follow'), 'url' => $url];

        $beats = $this->timed($beats, $match, $t, $sides, $goals);
        $at = 0.0;
        foreach ($beats as $i => $beat) {
            $beats[$i] += ['sides' => $sides, 'lang' => $lang];
            $beats[$i]['start'] = round($at, 3);
            $at += $beat['seconds'];
        }

        return [
            'beats' => $beats,
            'hook' => $generatedHook,
            'banter' => $generatedBanter,
            'question' => $question,
            'player_id' => $player?->id,
            'photo' => $photo ? array_diff_key($photo, ['file' => 1, 'path' => 1]) : null,
            'fingerprint' => self::fingerprint($match),
            'facts' => array_filter([
                'match_id' => $match->id, 'home' => ClubName::full($match->homeTeam), 'away' => ClubName::full($match->awayTeam),
                'score' => $score, 'competition' => $match->league?->name, 'kickoff' => SlideshowFacts::times($match)['utc'],
                'goals' => $goals->map(fn ($g) => array_intersect_key($g, array_flip(['minute_label', 'player', 'assist', 'side', 'own_goal', 'penalty'])))->all(),
                'red_cards' => $incidents->where('type', 'red_card')->map(fn ($r) => array_intersect_key($r, array_flip(['minute_label', 'player', 'side'])))->values()->all(),
                'standings' => $standings, 'featured_player' => $player?->name,
                'duration' => round(array_sum(array_column($beats, 'seconds')), 2),
            ], fn ($v) => $v !== null && $v !== []),
        ];
    }

    /** Changes when the score or the goals and red cards change (a story is then drawn again once). */
    public static function fingerprint(FootballMatch $match): string
    {
        $events = MatchEvent::where('match_id', $match->id)->whereIn('type', ['goal', 'red_card'])
            ->orderBy('id')->get(['type', 'minute', 'extra_minute', 'player_name', 'assist_player_name', 'team_id', 'is_own_goal'])
            ->map(fn ($e) => implode('|', [$e->type, $e->minute, $e->extra_minute, $e->player_name, $e->assist_player_name, $e->team_id, (int) $e->is_own_goal]));

        return hash('sha256', "{$match->home_score}-{$match->away_score}#".$events->join(';'));
    }

    // ------------------------------------------------------------------ timing

    /**
     * Fits the beats into 61-75 s. Every cut starts at 4 s (the hook at 3 s); short stories stretch
     * cuts to 5 s and then add verified recap cuts; long ones shrink cuts to 3 s and then drop the
     * least important optional beats.
     */
    public function timed(array $beats, FootballMatch $match, StoryText $t, array $sides, Collection $goals): array
    {
        [$lo, $hi] = config('story.cut', [3.0, 5.0]);
        [$targetLo, $targetHi] = config('story.target', [65, 75]);
        $max = (float) config('story.max_seconds', 75);
        foreach ($beats as $i => $beat) {
            $beats[$i]['seconds'] = $beat['type'] === 'hook' ? (float) config('story.hook_seconds', 3.0) : 4.0;
        }
        // By reference: the beats change below.
        $total = function () use (&$beats) {
            return array_sum(array_column($beats, 'seconds'));
        };
        $flexible = function () use (&$beats) {
            return array_keys(array_filter($beats, fn ($b) => $b['type'] !== 'hook'));
        };

        if ($total() < $targetLo) {
            // Stretch every cut toward 5 s, the close and the hook-adjacent build-up first.
            foreach (['close', 'buildup', 'goal', 'red', 'banter'] as $type) {
                foreach ($beats as $i => $b) {
                    if ($b['type'] === $type && $total() < $targetLo + 2) {
                        $beats[$i]['seconds'] = $hi;
                    }
                }
            }
            foreach ($flexible() as $i) {
                if ($total() < $targetLo + 2) {
                    $beats[$i]['seconds'] = $hi;
                }
            }
            // Still short: distinct verified recap cuts before the banter (final score with scorers, match
            // facts, the verdict, one stat at a time). Never the same card twice: when they run out, the
            // story is as long as the data allows.
            $pads = $this->padding($match, $t, $sides, $goals);
            $n = 0;
            while ($total() < $targetLo && $n < count($pads)) {
                $pad = $pads[$n] + ['keep' => false, 'rank' => 10, 'seconds' => $hi, 'padding' => true];
                $at = array_search('banter', array_column($beats, 'type'), true);
                array_splice($beats, $at === false ? count($beats) - 1 : $at, 0, [$pad]);
                $n++;
            }
        }

        if ($total() > $targetHi) {
            // Shrink the optional beats first, then everything, toward 3 s.
            foreach ([false, true] as $all) {
                foreach ($flexible() as $i) {
                    if ($total() > $targetHi && ($all || !$beats[$i]['keep'])) {
                        $beats[$i]['seconds'] = max($lo, $beats[$i]['seconds'] - min($beats[$i]['seconds'] - $lo, $total() - $targetHi));
                    }
                }
            }
            // Then drop optional beats, least important first, while the rest stays at least 61 s.
            $optional = collect($beats)->filter(fn ($b) => !$b['keep'])->sortBy('rank')->keys();
            foreach ($optional as $i) {
                if ($total() <= $max) {
                    break;
                }
                if ($total() - $beats[$i]['seconds'] >= (float) config('story.min_seconds', 61)) {
                    unset($beats[$i]);
                }
            }
            $beats = array_values($beats);
            // A match with a huge number of goals: share the time evenly so it still ends by 75 s.
            if ($total() > $max) {
                $scale = ($max - (float) config('story.hook_seconds', 3.0)) / ($total() - (float) config('story.hook_seconds', 3.0));
                foreach ($beats as $i => $b) {
                    if ($b['type'] !== 'hook') {
                        $beats[$i]['seconds'] = floor($b['seconds'] * $scale * 100) / 100;
                    }
                }
            }
        }

        return array_values($beats);
    }

    /** Verified recap cuts used only to reach the minimum length. */
    private function padding(FootballMatch $match, StoryText $t, array $sides, Collection $goals): array
    {
        $scorers = $goals->map(fn ($g) => trim(($g['minute_label'] ?? '').' '.$g['player'].($g['own_goal'] ? ' (og)' : ($g['penalty'] ? ' (pen)' : ''))))->filter()->values()->all();
        $pads = [['type' => 'recap', 'title' => $t->t('final_score'), 'score' => "{$match->home_score}-{$match->away_score}",
            'lines' => $scorers ?: [$t->t('no_goals')]]];
        $facts = array_values(array_filter([
            $match->home_score_ht !== null && $match->away_score_ht !== null ? $t->t('half_time', ['score' => "{$match->home_score_ht}-{$match->away_score_ht}"]) : null,
            $match->venue_name ? $t->t('at_venue', ['venue' => $match->venue_name]) : null,
            $match->referee_name ? $t->t('referee', ['name' => $match->referee_name]) : null,
            $match->attendance ? $t->t('attendance', ['n' => $t->number((int) $match->attendance)]) : null,
            $t->t('kickoff_at', ['time' => SlideshowFacts::times($match)['date'].', '.SlideshowFacts::times($match)['uk']]),
        ]));
        $pads[] = ['type' => 'facts', 'title' => $t->t('match_facts'), 'lines' => $facts];
        $winner = $match->home_score === $match->away_score ? $t->t('draw')
            : $t->t('win_for', ['club' => $match->home_score > $match->away_score ? $sides['home']['name'] : $sides['away']['name']]);
        // The previous meeting on record, with its scorers (from the right side's point of view).
        $last = FootballMatch::with(['homeTeam', 'awayTeam'])->where('id', '!=', $match->id)->where('match_date', '<', $match->match_date)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('home_team_id', $match->home_team_id)->where('away_team_id', $match->away_team_id))
                ->orWhere(fn ($q) => $q->where('home_team_id', $match->away_team_id)->where('away_team_id', $match->home_team_id)))
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])->whereNotNull('home_score')->whereNotNull('away_score')
            ->orderByDesc('match_date')->first();
        if ($last) {
            $swap = $last->home_team_id !== $match->home_team_id;
            $scorers = $this->incidents($last)->where('type', 'goal')->map(fn ($g) => trim(($g['minute_label'] ?? '').' '.$g['player'].($g['own_goal'] ? ' (og)' : '')))->filter()->values()->all();
            $pads[] = ['type' => 'recap', 'title' => $t->t('last_meetings', [], 1).' · '.SlideshowFacts::kickoff($last)->format('j M Y'),
                'score' => "{$last->home_score}-{$last->away_score}", 'lines' => array_slice($scorers ?: [$t->t('no_goals')], 0, 4),
                'sides' => $swap ? ['home' => $sides['away'], 'away' => $sides['home']] : $sides];
        }

        return $pads;
    }

    // ------------------------------------------------------------------ facts

    /** Both sides: short and full names, two colours (no crests), the flag of an African national team. */
    public function sides(FootballMatch $match): array
    {
        $side = function ($team, array $fallback) {
            $nation = AfricanScope::nationForTeam($team?->name);

            return ['name' => ClubName::short($team), 'full' => ClubName::full($team), 'flag' => $nation['flag'] ?? null,
                'colours' => array_values(SlideshowFacts::colours($team) ?? $fallback)];
        };
        $home = $side($match->homeTeam, Tokens::teamFallback('home'));
        $away = $side($match->awayTeam, Tokens::teamFallback('away'));
        if (strcasecmp($home['colours'][0], $away['colours'][0]) === 0) {
            $away['colours'] = [$away['colours'][1] ?? Tokens::colour('white'), $away['colours'][0]];
        }

        return ['home' => $home, 'away' => $away];
    }

    /** Goals and red cards in order, with the running score and labels. */
    public function incidents(FootballMatch $match): Collection
    {
        $t = StoryText::for('en');
        [$h, $a] = [0, 0];

        return MatchEvent::where('match_id', $match->id)->whereIn('type', ['goal', 'red_card'])
            ->orderBy('minute')->orderBy('extra_minute')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (MatchEvent $e) use ($match, &$h, &$a) {
                $side = $e->team_id === $match->away_team_id ? 'away' : 'home';
                $before = "{$h}-{$a}";
                if ($e->type === 'goal') {
                    $side === 'home' ? $h++ : $a++;
                }

                return [
                    'type' => $e->type, 'side' => $side, 'minute' => $e->minute, 'extra' => $e->extra_minute,
                    'minute_label' => $e->minute !== null ? $e->minute.($e->extra_minute ? '+'.$e->extra_minute : '')."'" : null,
                    'player' => trim((string) $e->player_name) ?: null, 'assist' => trim((string) $e->assist_player_name) ?: null,
                    'own_goal' => (bool) $e->is_own_goal, 'penalty' => (bool) $e->is_penalty,
                    'before' => $before, 'after' => "{$h}-{$a}", 'home_after' => $h, 'away_after' => $a,
                ];
            })->values();
    }

    /** The featured in-scope player (best part in the match, a licensed photo preferred) and that photo. */
    public function featured(FootballMatch $match): array
    {
        $candidates = SlideshowFacts::inScopePlayers($match);
        $played = $candidates->filter(fn ($c) => $c['match']['played'] || $c['match']['starter']);
        $pool = $played->isNotEmpty() ? $played : $candidates;
        foreach ($pool->take(4) as $candidate) {
            $photo = $this->photo($candidate['player']);
            if ($photo) {
                return [$candidate, $photo];
            }
        }

        return [$pool->first(), null];
    }

    /** Only the licence-checked Commons library (credit stored on the player); nothing else is used. */
    private function photo(Player $player): ?array
    {
        $photo = $this->photos->forPlayer($player);

        return $photo && !empty($photo['file']) && is_file($photo['file']) && !empty($photo['credit']) && CommonsPhotos::freeLicence($photo['licence'] ?? null) ? $photo : null;
    }

    private function buildup(FootballMatch $match, StoryText $t, array $sides, array $standings): array
    {
        $times = SlideshowFacts::times($match);
        $stage = data_get($match->metadata, 'stage') ?? data_get($match->metadata, 'round');
        $matchday = data_get($match->metadata, 'matchday');
        $lines = [];
        if ($standings['home'] && $standings['away']) {
            $updated = Standing::where('league_id', $match->league_id)->whereIn('team_id', [$match->home_team_id, $match->away_team_id])->max('updated_at');
            $before = $updated && Carbon::parse($updated)->lt(SlideshowFacts::kickoff($match));
            $lines[] = $t->t($before ? 'table_going_in' : 'table_now', ['home' => $sides['home']['name'], 'hpos' => $t->ordinal($standings['home']['position']),
                'away' => $sides['away']['name'], 'apos' => $t->ordinal($standings['away']['position'])]);
        }
        $lines[] = $t->t('kickoff_at', ['time' => $times['date'].', '.$times['uk']]);
        if ($match->venue_name) {
            $lines[] = $t->t('at_venue', ['venue' => $match->venue_name]);
        }

        return ['title' => $t->t('what_at_stake'), 'competition' => (string) ($match->league?->name ?: 'Football'),
            'stage' => is_string($stage) && $stage !== '' ? $stage : (is_numeric($matchday) ? 'Matchday '.$matchday : null), 'lines' => $lines];
    }

    private function stats(FootballMatch $match, StoryText $t): array
    {
        $rows = [];
        foreach (['possession' => 'possession', 'shots' => 'shots', 'on_target' => 'shots_on_target', 'corners' => 'corners', 'fouls' => 'fouls', 'yellow_cards' => 'yellow_cards'] as $label => $col) {
            $h = $match->{"home_{$col}"};
            $a = $match->{"away_{$col}"};
            if ($h === null || $a === null) {
                continue;
            }
            $rows[] = ['label' => $t->t($label), 'home' => (int) $h, 'away' => (int) $a, 'percent' => $label === 'possession'];
        }

        return count($rows) >= 2 ? array_slice($rows, 0, 5) : [];
    }

    private function playerLines(array $featured, FootballMatch $match, StoryText $t): array
    {
        $here = $featured['match'];
        $lines = [];
        $this_ = array_filter([
            $here['goals'] ? $t->t('goals_n', [], $here['goals']) : null,
            $here['assists'] ? $t->t('assists_n', [], $here['assists']) : null,
            $here['starter'] === true ? $t->t('started') : ($here['starter'] === false && $here['played'] ? $t->t('came_on') : null),
            $here['minutes'] ? $t->t('minutes', ['n' => $here['minutes']]) : null,
            $here['rating'] ? $t->t('rating', ['n' => $here['rating']]) : null,
        ]);
        if ($this_) {
            $lines[] = ['head' => $t->t('player_this_match'), 'text' => implode(' · ', $this_)];
        }
        $season = SlideshowFacts::playerSeason($featured['player'], $match->league, now('UTC'));
        if ($season && ($season['goals'] || $season['assists'])) {
            $lines[] = ['head' => $t->t('player_season', ['league' => $season['league']]),
                'text' => implode(' · ', [$t->t('goals_n', [], $season['goals']), $t->t('assists_n', [], $season['assists'])])];
        }

        return $lines;
    }

    private function headToHead(FootballMatch $match, StoryText $t, array $sides): ?array
    {
        $meetings = FootballMatch::where('id', '!=', $match->id)
            ->where('match_date', '<', $match->match_date)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('home_team_id', $match->home_team_id)->where('away_team_id', $match->away_team_id))
                ->orWhere(fn ($q) => $q->where('home_team_id', $match->away_team_id)->where('away_team_id', $match->home_team_id)))
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])->whereNotNull('home_score')->whereNotNull('away_score')
            ->orderByDesc('match_date')->limit(5)->get();
        if ($meetings->isEmpty()) {
            return null;
        }
        $w = ['home' => 0, 'draw' => 0, 'away' => 0];
        $list = [];
        foreach ($meetings as $m) {
            $homeHere = $m->home_team_id === $match->home_team_id;
            [$for, $against] = $homeHere ? [$m->home_score, $m->away_score] : [$m->away_score, $m->home_score];
            $w[$for > $against ? 'home' : ($for < $against ? 'away' : 'draw')]++;
            $list[] = ($homeHere ? $sides['home']['name'] : $sides['away']['name']).' '.$m->home_score.'-'.$m->away_score.' '.($homeHere ? $sides['away']['name'] : $sides['home']['name'])
                .' ('.SlideshowFacts::kickoff($m)->format('M Y').')';
        }

        return ['title' => $t->t('head_to_head'), 'sub' => $t->t('last_meetings', [], $meetings->count()),
            'counts' => [['label' => $t->t('wins', ['club' => $sides['home']['name']]), 'n' => $w['home'], 'side' => 'home'],
                ['label' => $t->t('draws'), 'n' => $w['draw'], 'side' => null],
                ['label' => $t->t('wins', ['club' => $sides['away']['name']]), 'n' => $w['away'], 'side' => 'away']],
            'lines' => array_slice($list, 0, 3)];
    }

    /** The league table rows around both clubs (top three and both sides), highlighted. */
    private function table(FootballMatch $match, StoryText $t): array
    {
        if (!$match->league_id) {
            return [];
        }
        $query = Standing::with('team')->where('league_id', $match->league_id);
        if ((clone $query)->where('is_current', true)->exists()) {
            $query->where('is_current', true);
        }
        $rows = $query->orderBy('position')->get()->unique('team_id')->filter(fn ($r) => $r->position);
        if (!$rows->firstWhere('team_id', $match->home_team_id) || !$rows->firstWhere('team_id', $match->away_team_id)) {
            return [];
        }
        $ids = [$match->home_team_id, $match->away_team_id];

        return $rows->filter(fn ($r) => $r->position <= 3 || in_array($r->team_id, $ids, true))->sortBy('position')->take(6)
            ->map(fn ($r) => ['pos' => (int) $r->position, 'name' => ClubName::short($r->team), 'played' => $r->matches_played !== null ? (int) $r->matches_played : null,
                'pts' => $r->points !== null ? (int) $r->points : null, 'side' => $r->team_id === $match->home_team_id ? 'home' : ($r->team_id === $match->away_team_id ? 'away' : null)])
            ->values()->all();
    }

    private function nextFixtures(FootballMatch $match, StoryText $t): array
    {
        $lines = [];
        foreach (['home' => $match->homeTeam, 'away' => $match->awayTeam] as $side => $team) {
            $next = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
                ->where(fn ($q) => $q->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
                ->where('id', '!=', $match->id)->where('match_date', '>', $match->match_date)
                ->whereNotIn('status', ['FINISHED', 'FT', 'AWARDED', 'CANCELLED', 'POSTPONED'])
                ->orderBy('match_date')->first();
            if ($next) {
                $times = SlideshowFacts::times($next);
                $lines[] = ['side' => $side, 'text' => ClubName::short($next->homeTeam).' '.$t->t('vs').' '.ClubName::short($next->awayTeam),
                    'when' => $times['day'].', '.$times['uk'].($next->league ? ' · '.$next->league->name : '')];
            }
        }

        return $lines;
    }

    // ------------------------------------------------------------------ words

    public function hook(FootballMatch $match, StoryText $t, array $sides, Collection $incidents, array $standings, ?Player $player): string
    {
        $goals = $incidents->where('type', 'goal')->values();
        $h = (int) $match->home_score;
        $a = (int) $match->away_score;
        $winner = $h === $a ? null : ($h > $a ? 'home' : 'away');
        $last = $goals->last();
        $name = fn (?string $n) => (string) $n;

        if ($last && !$last['own_goal'] && $last['player'] && (int) $last['minute'] >= 80) {
            if ($winner === null && $h > 0) {
                return $t->t('hook_late_equaliser', ['name' => $name($last['player']), 'minute' => $t->minute($last['minute'], $last['extra'])]);
            }
            [$lh, $la] = explode('-', $last['before']);
            $wasLevel = (int) $lh === (int) $la;
            if ($winner === $last['side'] && $wasLevel) {
                return $t->t('hook_late_winner', ['name' => $name($last['player']), 'minute' => $t->minute($last['minute'], $last['extra'])]);
            }
        }
        $byPlayer = $goals->filter(fn ($g) => !$g['own_goal'] && $g['player'])->groupBy('player')->map->count()->sortDesc();
        if ($byPlayer->isNotEmpty() && $byPlayer->first() >= 2) {
            $top = $player && $byPlayer->keys()->first(fn ($k) => PlayerNames::same($player->name, $k) && $byPlayer[$k] >= 2) ? $player->name : $byPlayer->keys()->first();

            return $t->t($byPlayer->first() >= 3 ? 'hook_hat_trick' : 'hook_brace', ['name' => $name($top)]);
        }
        $red = $incidents->firstWhere('type', 'red_card');
        if ($red && $red['player']) {
            return $t->t('hook_red', ['name' => $name($red['player'])]);
        }
        if ($winner && $goals->contains(fn ($g) => $winner === 'home' ? $g['away_after'] > $g['home_after'] : $g['home_after'] > $g['away_after'])) {
            return $t->t('hook_comeback', ['club' => $sides[$winner]['name']]);
        }
        $loser = $winner === 'home' ? 'away' : 'home';
        if ($winner && ($standings[$winner]['position'] ?? 0) - ($standings[$loser]['position'] ?? 99) >= 8) {
            return $t->t('hook_upset', ['pos' => $t->ordinal($standings[$winner]['position']), 'club' => $sides[$winner]['name'], 'opp' => $sides[$loser]['name']]);
        }
        if ($winner && abs($h - $a) >= 3) {
            return $t->t('hook_thrash', ['club' => $sides[$winner]['name'], 'score' => "{$h}-{$a}"]);
        }
        if ($player && $goals->contains(fn ($g) => !$g['own_goal'] && $g['player'] && PlayerNames::same($player->name, $g['player']))) {
            return $t->t('hook_scored', ['name' => $name($player->name)]);
        }

        return $t->t('hook_default', ['home' => $sides['home']['name'], 'away' => $sides['away']['name'], 'score' => "{$h}-{$a}"]);
    }

    public function banter(FootballMatch $match, StoryText $t, array $sides): string
    {
        $h = (int) $match->home_score;
        $a = (int) $match->away_score;
        if ($h === $a) {
            return $t->t($h === 0 ? 'banter_goalless' : 'banter_draw');
        }
        [$w, $l] = $h > $a ? ['home', 'away'] : ['away', 'home'];

        return $t->t(abs($h - $a) >= 3 ? 'banter_win_big' : 'banter_win', ['winner' => $sides[$w]['name'], 'loser' => $sides[$l]['name']]);
    }

    private function question(FootballMatch $match, StoryText $t, array $sides, ?Player $player, ?array $featured): string
    {
        if ($player && $featured && ($featured['match']['played'] ?? false)) {
            return $t->t('q_player', ['name' => $player->name]);
        }
        $h = (int) $match->home_score;
        $a = (int) $match->away_score;

        return $h === $a ? $t->t('q_draw') : $t->t('q_win', ['club' => $sides[$h > $a ? 'home' : 'away']['name']]);
    }
}
