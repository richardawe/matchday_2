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
    /** [name, side] of the player the last generated hook named, if any. */
    private ?array $named = null;

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
        [$generatedHook, $lead, $hookBeat] = $this->opening($match, $lang, $hook, $sides, $incidents, $standings, $featured);
        $generatedBanter = $this->banter($match, $t, $sides);
        $hookLine = trim((string) $hook) !== '' ? trim((string) $hook) : $generatedHook;
        $banterLine = trim((string) $banter) !== '' ? trim((string) $banter) : $generatedBanter;
        $question = $this->question($match, $t, $sides, $player, $featured);
        $url = 'matchday.africa/matches/'.$match->id;

        $beats = [];
        $beats[] = $hookBeat;
        $beats[] = ['type' => 'buildup', 'keep' => true, 'rank' => 90] + $this->buildup($match, $t, $sides, $standings);
        foreach ($incidents as $i => $incident) {
            $beats[] = ['type' => $incident['type'] === 'goal' ? 'goal' : 'red', 'keep' => true, 'rank' => 95] + $incident;
        }
        // Optional beats, best first (rank): the lead scorer's record, the lead player's profile, the table
        // and what it means, the next fixtures, other results that day, head-to-head (3+ meetings), key
        // stats, form. The timing drops the lowest ranks when a story runs long.
        if ($lead && ($record = $this->record($match, $t, $lead))) {
            $beats[] = ['type' => 'record', 'keep' => false, 'rank' => 80] + $record;
        }
        if ($lead && ($profile = $this->profileBeat($match, $t, $lead, $sides))) {
            $beats[] = ['type' => 'player', 'keep' => false, 'rank' => 76] + $profile;
        }
        if ($table = $this->table($match, $t)) {
            $beats[] = ['type' => 'table', 'keep' => false, 'rank' => 72, 'title' => $t->t('table'), 'rows' => $table, 'note' => $this->tableNote($match, $t, $sides)];
        }
        if ($next = $this->nextFixtures($match, $t)) {
            $beats[] = ['type' => 'next', 'keep' => false, 'rank' => 68, 'title' => $t->t('next_fixture'), 'lines' => $next];
        }
        foreach ($this->elsewhere($match, $t) as $i => $chunk) {
            $beats[] = ['type' => 'elsewhere', 'keep' => false, 'rank' => 64 - $i] + $chunk;
        }
        if ($h2h = $this->headToHead($match, $t, $sides)) {
            $beats[] = ['type' => 'h2h', 'keep' => false, 'rank' => 58] + $h2h;
        }
        if ($stats = $this->stats($match, $t)) {
            $beats[] = ['type' => 'stats', 'keep' => false, 'rank' => 55, 'title' => $t->t('key_stats'), 'rows' => $stats];
        }
        $form = ['home' => SlideshowFacts::form($match->homeTeam, $match), 'away' => SlideshowFacts::form($match->awayTeam, $match)];
        if ($form['home'] || $form['away']) {
            $beats[] = ['type' => 'form', 'keep' => false, 'rank' => 45, 'title' => $t->t('form'), 'form' => $form];
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
            'player_id' => $lead['player']?->id ?? $player?->id,
            'photo' => !empty($lead['photo']) ? array_diff_key($lead['photo'], ['file' => 1, 'path' => 1]) : null,
            'fingerprint' => self::fingerprint($match),
            'facts' => array_filter([
                'match_id' => $match->id, 'home' => ClubName::full($match->homeTeam), 'away' => ClubName::full($match->awayTeam),
                'score' => $score, 'competition' => $match->league?->name, 'kickoff' => SlideshowFacts::times($match)['utc'],
                'goals' => $goals->map(fn ($g) => array_intersect_key($g, array_flip(['minute_label', 'player', 'assist', 'side', 'own_goal', 'penalty'])))->all(),
                'red_cards' => $incidents->where('type', 'red_card')->map(fn ($r) => array_intersect_key($r, array_flip(['minute_label', 'player', 'side'])))->values()->all(),
                'standings' => $standings, 'featured_player' => $lead['name'] ?? $player?->name,
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
            // Still short: the final score with the scorers and the match facts, once each.
            foreach ($this->padding($match, $t, $sides, $goals) as $pad) {
                if ($total() >= $targetLo) {
                    break;
                }
                $at = array_search('banter', array_column($beats, 'type'), true);
                array_splice($beats, $at === false ? count($beats) - 1 : $at, 0, [$pad + ['keep' => false, 'rank' => 10, 'seconds' => $hi, 'padding' => true]]);
            }
            // A card is never shown twice to make up the time: without enough distinct verified beats
            // the story is not made.
            if ($total() < (float) config('story.min_seconds', 61)) {
                throw new StoryTooShort(sprintf('Not enough distinct verified content for a %d s story: %d different cards make %.0f s at %.0f s each. '
                    .'Add data (table, fixtures, line-ups) or render a shorter format; cards are never repeated.',
                    (int) config('story.min_seconds', 61), count($beats), $total(), $hi));
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
            'lines' => array_slice($scorers ?: [$t->t('no_goals')], 0, 5)]];
        $facts = array_values(array_filter([
            $match->home_score_ht !== null && $match->away_score_ht !== null ? $t->t('half_time', ['score' => "{$match->home_score_ht}-{$match->away_score_ht}"]) : null,
            $match->venue_name ? $t->t('at_venue', ['venue' => $match->venue_name]) : null,
            $match->referee_name ? $t->t('referee', ['name' => $match->referee_name]) : null,
            $match->attendance ? $t->t('attendance', ['n' => $t->number((int) $match->attendance)]) : null,
            $t->t('kickoff_at', ['time' => SlideshowFacts::times($match)['date'].', '.SlideshowFacts::times($match)['uk']]),
        ]));
        $pads[] = ['type' => 'facts', 'title' => $t->t('match_facts'), 'lines' => $facts];

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
        if ($meetings->count() < 3) {
            return null; // head-to-head only means something over three or more meetings
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

    /**
     * The hook: a provocative but true line or question from the data, sentence case, at most 8 words.
     * In order: a late winner or equaliser (80'+), an upset (the side 4+ places lower in the table wins),
     * a red card, a brace or hat-trick, a 1-0, a 0-0; otherwise a question about the result.
     */
    public function hook(FootballMatch $match, StoryText $t, array $sides, Collection $incidents, array $standings, ?array $lead = null): string
    {
        $goals = $incidents->where('type', 'goal')->values();
        $h = (int) $match->home_score;
        $a = (int) $match->away_score;
        $winner = $h === $a ? null : ($h > $a ? 'home' : 'away');
        $loser = $winner === 'home' ? 'away' : 'home';
        $short = fn (string $side) => $sides[$side]['name'];
        $lines = [];
        $this->named = null;
        $last = $goals->last();
        if ($last && !$last['own_goal'] && $last['player'] && (int) $last['minute'] >= 80) {
            [$lh, $la] = array_map('intval', explode('-', $last['before']));
            $equaliser = $winner === null && $h > 0;
            $winnerGoal = $winner === $last['side'] && $lh === $la;
            if ($equaliser || $winnerGoal) {
                // Whose hearts broke: the loser, or the side that led before the equaliser.
                $victim = $winnerGoal ? $loser : ($last['side'] === 'home' ? 'away' : 'home');
                $lines[] = [$t->t('hook_late', ['name' => $last['player'], 'club' => $short($victim), 'minute' => $last['minute_label']]),
                    $t->t('hook_late', ['name' => PlayerNames::surname($last['player']), 'club' => $short($victim), 'minute' => $last['minute_label']]),
                    'named' => [$last['player'], $last['side']]];
            }
        }
        if ($winner && isset($standings[$winner]['position'], $standings[$loser]['position']) && $standings[$winner]['position'] - $standings[$loser]['position'] >= 4) {
            $lines[] = [$t->t('hook_upset', ['club' => $short($winner)])];
        }
        if ($red = $incidents->firstWhere('type', 'red_card')) {
            $lines[] = [$t->t('hook_red', ['club' => $short($red['side'])])];
        }
        $byPlayer = $goals->filter(fn ($g) => !$g['own_goal'] && $g['player'])->groupBy('player')->map->count()->sortDesc();
        if ($byPlayer->isNotEmpty() && $byPlayer->first() >= 2) {
            $name = (string) $byPlayer->keys()->first();
            $lines[] = [$t->t('hook_goals', ['name' => $name]), $t->t('hook_goals', ['name' => PlayerNames::surname($name)]),
                'named' => [$name, $goals->firstWhere('player', $name)['side']]];
        }
        if (max($h, $a) === 1 && min($h, $a) === 0 && ($g = $goals->first()) && !$g['own_goal'] && $g['player']) {
            $lines[] = [$t->t('hook_one_nil', ['name' => $g['player']]), $t->t('hook_one_nil', ['name' => PlayerNames::surname($g['player'])]),
                'named' => [$g['player'], $g['side']]];
        }
        if ($h === 0 && $a === 0) {
            $lines[] = [$t->t('hook_goalless', ['club' => $short((int) $match->away_shots > (int) $match->home_shots ? 'away' : 'home')])];
        }
        $lines[] = $winner ? [$t->t('hook_win', ['club' => $short($winner), 'score' => $winner === 'home' ? "{$h}-{$a}" : "{$a}-{$h}"])]
            : [$t->t('hook_draw', ['home' => $short('home'), 'away' => $short('away')])];
        foreach ($lines as $options) {
            $named = $options['named'] ?? null;
            unset($options['named']);
            foreach ($options as $line) {
                if (self::words($line) <= 8) {
                    $this->named = $named;

                    return $line;
                }
            }
        }

        return $t->t('hook_fallback');
    }

    /**
     * The opening card: [generated hook, lead player, hook beat (with sides and language)]. The lead
     * follows the priority order, except that a generated hook naming a player opens on that player.
     * An editor's hook always replaces the generated line.
     */
    public function opening(FootballMatch $match, string $lang = 'en', ?string $hook = null, ?array $sides = null, ?Collection $incidents = null,
        ?array $standings = null, ?array $featured = null): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        $t = StoryText::for($lang);
        $sides ??= $this->sides($match);
        $incidents ??= $this->incidents($match);
        $standings ??= ['home' => SlideshowFacts::standing($match->homeTeam, $match->league), 'away' => SlideshowFacts::standing($match->awayTeam, $match->league)];
        $featured ??= $this->featured($match)[0];
        $lead = $this->leadPlayer($match, $incidents, $sides, $featured);
        $generated = $this->hook($match, $t, $sides, $incidents, $standings, $lead);
        if (trim((string) $hook) === '' && $this->named && (!$lead || !PlayerNames::same($lead['name'], $this->named[0]))) {
            $lead = $this->leadPlayer($match, $incidents, $sides, $featured, $this->named + [2 => 'hook']);
        }
        $beat = ['type' => 'hook', 'keep' => true, 'rank' => 100, 'line' => trim((string) $hook) !== '' ? trim((string) $hook) : $generated,
            'score' => "{$match->home_score}-{$match->away_score}",
            'player' => $lead ? ['name' => $lead['name'], 'label' => $lead['label'], 'club' => $lead['club'], 'flag' => $lead['flag'], 'side' => $lead['side']] : null,
            'photo' => $lead['photo'] ?? null, 'sides' => $sides, 'lang' => $lang, 'start' => 0.0, 'seconds' => (float) config('story.hook_seconds', 3.0)];

        return [$generated, $lead, $beat];
    }

    /** The generated hook for a match on its own (studio previews, tests). */
    public function hookFor(FootballMatch $match, string $lang = 'en'): string
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        $sides = $this->sides($match);
        $incidents = $this->incidents($match);
        $standings = ['home' => SlideshowFacts::standing($match->homeTeam, $match->league), 'away' => SlideshowFacts::standing($match->awayTeam, $match->league)];

        return $this->hook($match, StoryText::for($lang), $sides, $incidents, $standings);
    }

    /** Words in a line, as the 8-word rule counts them ("90+6'" is one). */
    public static function words(string $line): int
    {
        return count(preg_split('/\s+/u', trim($line)) ?: []);
    }

    /**
     * The player the story opens on: the scorer of the winning goal (or of the last goal in a draw),
     * else the match's top scorer, else the best-rated African player in the line-up. With their row
     * when we have one, and a licensed photo from the player media library (by row, then by name).
     * ['name', 'player' => ?Player, 'side', 'photo' => ?array, 'flag', 'label', 'club', 'reason'] or null.
     */
    public function leadPlayer(FootballMatch $match, Collection $incidents, array $sides, ?array $featured = null, ?array $pick = null): ?array
    {
        $goals = $incidents->where('type', 'goal')->values();
        $h = (int) $match->home_score;
        $a = (int) $match->away_score;
        if ($pick) {
            // A chosen [name, side, reason].
        } elseif ($h !== $a) {
            $w = $h > $a ? 'home' : 'away';
            $need = min($h, $a) + 1;
            $decisive = $goals->first(fn ($g) => $g['side'] === $w && ($w === 'home' ? $g['home_after'] : $g['away_after']) === $need);
            if ($decisive && !$decisive['own_goal'] && $decisive['player']) {
                $pick = [$decisive['player'], $w, 'winning_goal'];
            }
        } elseif (($last = $goals->last()) && !$last['own_goal'] && $last['player']) {
            $pick = [$last['player'], $last['side'], 'last_goal'];
        }
        if (!$pick) {
            $top = $goals->filter(fn ($g) => !$g['own_goal'] && $g['player'])->groupBy('player')->sortByDesc(fn ($g) => $g->count())->first();
            if ($top) {
                $pick = [$top->first()['player'], $top->first()['side'], 'top_scorer'];
            }
        }
        if (!$pick) {
            $rated = SlideshowFacts::inScopePlayers($match)->filter(fn ($c) => ($c['match']['rating'] ?? null) !== null)->sortByDesc(fn ($c) => $c['match']['rating'])->first()
                ?? ($featured && ($featured['match']['played'] ?? false) ? $featured : null);
            if ($rated) {
                $pick = [$rated['player']->name, $rated['player']->team_id === $match->away_team_id ? 'away' : 'home', 'rating'];
            }
        }
        if (!$pick) {
            return null;
        }
        [$name, $side, $reason] = $pick;
        $row = Player::with('team')->whereIn('team_id', [$match->home_team_id, $match->away_team_id])->get()
            ->first(fn (Player $p) => PlayerNames::same($p->name, $name));
        $photo = $row ? $this->photo($row) : null;
        $photo ??= $this->namedPhoto($row?->name ?? $name);
        $team = $side === 'home' ? $match->homeTeam : $match->awayTeam;
        $national = (bool) ($team?->is_national_team ?? false) || AfricanScope::nationForTeam($team?->name);
        $flag = $row ? (AfricanScope::nation(AfricanScope::countryCode($row))['flag'] ?? null) : null;

        return [
            'name' => $row?->name ?? $name, 'player' => $row, 'side' => $side, 'reason' => $reason,
            'photo' => $photo ? ['file' => $photo['file'], 'crop' => $photo['crop'] ?? null, 'credit' => $photo['credit']]
                + array_intersect_key($photo, array_flip(['licence', 'artist', 'title', 'source_url', 'licence_url'])) : null,
            'flag' => $flag ?? $sides[$side]['flag'],
            'label' => $row ? (AfricanScope::label($row) ?? $row->nationality) : ($national ? $sides[$side]['name'].' international' : null),
            'club' => $row && !$national ? ClubName::short($row->team) : (!$national ? $sides[$side]['name'] : null),
        ];
    }

    /** A licensed photo by name from the player media library (checked like the row photos). */
    private function namedPhoto(string $name): ?array
    {
        $photo = $this->photos->forName($name);

        return $photo && !empty($photo['file']) && is_file($photo['file']) && !empty($photo['credit']) && CommonsPhotos::freeLicence($photo['licence'] ?? null) ? $photo : null;
    }

    /**
     * The lead scorer's record from our data: their other goals for this side (newest first) and, in a
     * league whose season totals we trust, this season's total. Null when this match is all we have.
     */
    private function record(FootballMatch $match, StoryText $t, array $lead): ?array
    {
        $teamId = $lead['side'] === 'home' ? $match->home_team_id : $match->away_team_id;
        $events = MatchEvent::with(['match.homeTeam', 'match.awayTeam'])->where('type', 'goal')->where('team_id', $teamId)
            ->where('match_id', '!=', $match->id)->whereNotNull('player_name')->where(fn ($q) => $q->whereNull('is_own_goal')->orWhere('is_own_goal', false))
            ->whereHas('match', fn ($q) => $q->where('match_date', '<', $match->match_date)->whereIn('status', ['FINISHED', 'FT', 'AWARDED']))
            ->get()->filter(fn ($e) => PlayerNames::same($lead['name'], (string) $e->player_name))
            ->sortByDesc(fn ($e) => $e->match->match_date)->values();
        if ($events->isEmpty()) {
            return null;
        }
        $lines = $events->groupBy('match_id')->take(4)->map(function ($group) use ($teamId, $t) {
            $m = $group->first()->match;
            $opp = $m->home_team_id === $teamId ? $m->awayTeam : $m->homeTeam;

            return $t->t('record_line', ['opp' => ClubName::short($opp), 'date' => SlideshowFacts::kickoff($m)->format('j M Y')], $group->count());
        })->values()->all();
        if ($lead['player'] && ($season = SlideshowFacts::playerSeason($lead['player'], $match->league, SlideshowFacts::kickoff($match)->addHours(4)))) {
            array_unshift($lines, $t->t('record_season', ['league' => $season['league']], $season['goals']));
        }

        return ['title' => $t->t('record_title', ['name' => PlayerNames::surname($lead['name'])]), 'lines' => $lines];
    }

    /** The lead player's profile when we know more than the hook shows: this match and this season. */
    private function profileBeat(FootballMatch $match, StoryText $t, array $lead, array $sides): ?array
    {
        if (!$lead['player']) {
            return null;
        }
        $lines = $this->playerLines(['player' => $lead['player'], 'match' => SlideshowFacts::playerInMatch($lead['player'], $match)], $match, $t);
        if (!$lines) {
            return null;
        }

        return ['name' => $lead['name'], 'label' => $lead['label'], 'club' => $lead['club'], 'lines' => $lines, 'photo' => $lead['photo'], 'flag' => $lead['flag']];
    }

    /** What the table says after the result, from the stored positions: "Morocco top the table on 6 points". */
    private function tableNote(FootballMatch $match, StoryText $t, array $sides): ?string
    {
        foreach (['home' => $match->homeTeam, 'away' => $match->awayTeam] as $side => $team) {
            $s = $team ? SlideshowFacts::standing($team, $match->league) : null;
            if ($s && $s['position'] === 1 && $s['points'] !== null) {
                return $t->t('table_top', ['club' => $sides[$side]['name'], 'pts' => $s['points']]);
            }
        }

        return null;
    }

    /** Other results in the same competition that day, four to a card (at most four cards). */
    private function elsewhere(FootballMatch $match, StoryText $t): array
    {
        if (!$match->league_id) {
            return [];
        }
        $day = SlideshowFacts::kickoff($match)->format('Y-m-d');
        $others = FootballMatch::with(['homeTeam', 'awayTeam'])->where('league_id', $match->league_id)->where('id', '!=', $match->id)
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])->whereNotNull('home_score')->whereNotNull('away_score')
            ->whereBetween('match_date', [$day.' 00:00:00', $day.' 23:59:59'])->orderBy('match_date')->orderBy('id')->get();
        $title = $t->t('elsewhere', ['competition' => (string) ($match->league?->name ?: 'Football')]);

        return $others->chunk(4)->take(4)->values()->map(fn ($chunk, $i) => ['title' => $title, 'part' => $i + 1,
            'results' => $chunk->map(fn ($m) => ['home' => ClubName::short($m->homeTeam), 'away' => ClubName::short($m->awayTeam),
                'score' => "{$m->home_score}-{$m->away_score}"])->values()->all()])->all();
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
