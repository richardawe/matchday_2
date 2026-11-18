<?php

namespace App\Services\Slideshow;

use App\Design\Tokens;
use App\Models\FootballMatch;
use App\Models\Player;
use App\Models\Slideshow;
use App\Services\Griot\GriotChallengeService;
use App\Services\SocialQueue;
use App\Support\AfricanScope;
use App\Support\ClubName;
use App\Support\SocialLink;
use App\Support\XText;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a slideshow's subject (a match, a player, a Premier League round) into five slides and
 * the captions, from SlideshowFacts only. Lines whose fact is missing are dropped; a slide with
 * nothing left is dropped. Slide 01 always features an in-scope player when there is one, and a
 * stat slide shows the in-scope players together. Returns null when the subject no longer qualifies.
 */
class SlideshowDeck
{
    public const MILESTONES = [5, 10, 15, 20, 25, 30];

    public function __construct(private CommonsPhotos $photos) {}

    /**
     * @return array{facts: array, slides: array, caption: string, captions: array, landing_path: string,
     *               player_id: ?int, photo: ?array, music: array}|null
     */
    public function build(Slideshow $show): ?array
    {
        return match ($show->type) {
            'preview', 'national' => $show->match ? $this->preview($show, $show->match) : null,
            'post_match' => $show->match ? $this->postMatch($show, $show->match) : null,
            'milestone' => $show->match && $show->player ? $this->milestone($show, $show->match, $show->player) : null,
            'totw' => $this->totw($show),
            default => null,
        };
    }

    // ---------------------------------------------------------------- decks
    // Running order follows the hand-built decks: 01 date, both sides, the player and the kick-off;
    // then the in-scope players, the table and form; last the question with the match card.

    private function preview(Slideshow $show, FootballMatch $match): ?array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        $players = SlideshowFacts::inScopePlayers($match);
        $national = $this->nations($match);
        if ($players->isEmpty() && !$national) {
            return null;
        }
        $featured = $players->first();
        $times = SlideshowFacts::times($match);
        [$home, $away] = [ClubName::full($match->homeTeam), ClubName::full($match->awayTeam)];
        $ctx = $this->context($match);
        $photo = $featured ? $this->photos->forPlayer($featured['player']) : null;
        $question = "Who wins: {$this->short($match->homeTeam)} or {$this->short($match->awayTeam)}?";

        $slides = array_values(array_filter([
            ...$this->openerSlides($ctx, $match, $featured, $photo, $times),
            $this->playersSlide($ctx, $players, 'preview', $match),
            $this->tableSlide($ctx, $match),
            $this->formSlide($ctx, $match),
            $this->ctaSlide($ctx, $match, $question, 'Call the score at matchday.africa before kick-off.', $times),
        ]));

        $label = $featured ? AfricanScope::label($featured['player']) : null;
        $lead = $featured ? "{$featured['player']->name} ({$label}) and ".$this->clubOf($featured['player'], $match).' ' : '';
        $body = $lead
            ? $lead.'face '.$this->opponentOf($featured['player'], $match)." on {$times['day']}. Kick-off {$times['uk']}, {$times['wat']}."
            : "{$home} v {$away} on {$times['day']}. Kick-off {$times['uk']}, {$times['wat']}.";
        $body .= "\n\n{$question} Call the score on matchday.africa.";

        return $this->finish($show, $slides, $body, route('matches.show', $match, false), $featured['player'] ?? null, $photo,
            $this->competitionTag($match), [
                'title' => "{$home} v {$away}",
                'match' => $this->matchFacts($match), 'players' => $this->playerFacts($players, $match),
                'national' => $national ? array_map(fn ($n) => $n['name'], $national) : null,
            ]);
    }

    private function postMatch(Slideshow $show, FootballMatch $match): ?array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        if (!SlideshowFacts::finished($match)) {
            return null;
        }
        $players = SlideshowFacts::inScopePlayers($match);
        $national = $this->nations($match);
        if ($players->isEmpty() && !$national) {
            return null;
        }
        $played = $players->filter(fn ($p) => $p['match']['played'])->values();
        $featured = $played->first() ?? $players->first();
        $ctx = $this->context($match);
        $times = SlideshowFacts::times($match);
        $photo = $featured ? $this->photos->forPlayer($featured['player']) : null;
        [$home, $away] = [ClubName::full($match->homeTeam), ClubName::full($match->awayTeam)];
        $score = "{$home} {$match->home_score}-{$match->away_score} {$away}";
        $question = $featured && $featured['match']['played']
            ? "How did {$this->surname($featured['player'])} play?"
            : "What did you make of {$this->short($match->homeTeam)} v {$this->short($match->awayTeam)}?";

        $slides = array_values(array_filter([
            ...$this->openerSlides($ctx, $match, $featured, $photo, $times),
            $played->isNotEmpty() ? $this->playersSlide($ctx, $played, 'post_match', $match) : null,
            $this->tableSlide($ctx, $match),
            $this->ctaSlide($ctx, $match, $question, 'Full story and stats at matchday.africa.', $times),
        ]));

        $body = "Full time: {$score}".($match->league?->name ? ' ('.$match->league->name.')' : '').'.';
        if ($featured && $featured['match']['played'] && ($line = $this->statLine($featured['match'], null, 'post_match'))) {
            $body .= "\n\n{$featured['player']->name} (".AfricanScope::label($featured['player'])."): {$line}.";
        }
        $body .= "\n\n{$question} Full story and stats on matchday.africa.";

        return $this->finish($show, $slides, $body, route('matches.show', $match, false), $featured['player'] ?? null, $photo,
            $this->competitionTag($match), ['title' => $score, 'match' => $this->matchFacts($match), 'goals' => SlideshowFacts::goals($match),
                'players' => $this->playerFacts($players, $match)]);
    }

    private function milestone(Slideshow $show, FootballMatch $match, Player $player): ?array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'league']);
        $player->loadMissing('team');
        if (!AfricanScope::inScope($player) || !SlideshowFacts::finished($match)) {
            return null;
        }
        $season = SlideshowFacts::playerSeason($player, $match->league, SlideshowFacts::kickoff($match)->addHours(4));
        $here = SlideshowFacts::playerInMatch($player, $match);
        $goals = (int) ($season['goals'] ?? 0);
        if ($here['goals'] < 1 || !in_array($goals, self::MILESTONES, true) || $goals !== (int) data_get($show->facts, 'goals', $goals)) {
            return null;
        }
        $ctx = $this->playerContext($player);
        $photo = $this->photos->forPlayer($player);
        $times = SlideshowFacts::times($match);
        $league = $match->league?->name ?? 'league';
        $entry = ['player' => $player, 'match' => $here, 'season' => $season];

        $slides = array_values(array_filter([
            $this->portraitSlide($ctx, 'Milestone · '.$league, $player, $photo, ['badge' => 'Milestone',
                'lines' => ["{$goals} {$league} goals this season", 'The latest in '.ClubName::short($match->homeTeam)." {$match->home_score}-{$match->away_score} ".ClubName::short($match->awayTeam)]]),
            ...$this->openerSlides($this->context($match), $match, null, null, $times),
            $this->ctaSlide($ctx, $match, "How many will {$this->surname($player)} finish the season on?", 'Follow every goal at matchday.africa.', $times),
        ]));
        $body = "{$player->name} (".AfricanScope::label($player).") has {$goals} {$league} goals this season after scoring in "
            .ClubName::full($match->homeTeam)." {$match->home_score}-{$match->away_score} ".ClubName::full($match->awayTeam).'.'
            ."\n\nHow many will {$this->surname($player)} finish the season on?";

        return $this->finish($show, $slides, $body, $this->playerPath($player, $match), $player, $photo,
            $this->competitionTag($match), ['title' => "{$player->name}: {$goals} {$league} goals", 'goals' => $goals,
                'match' => $this->matchFacts($match), 'players' => $this->playerFacts(collect([$entry]), $match)]);
    }

    /** Monday's Team of the Week: in-scope players with goals or assists in last week's Premier League matches. */
    private function totw(Slideshow $show): ?array
    {
        $rows = $this->totwRows(Carbon::parse((string) data_get($show->facts, 'week_start', now('UTC')->subWeek()->startOfWeek()->toDateString()), 'UTC'));
        if ($rows->isEmpty()) {
            return null;
        }
        $top = $rows->first();
        $player = $top['player'];
        $ctx = $this->playerContext($player);
        $photo = $this->photos->forPlayer($player);
        $range = data_get($show->facts, 'range') ?? $this->weekRange(Carbon::parse((string) data_get($show->facts, 'week_start'), 'UTC'));

        $slides = [$this->portraitSlide($ctx, 'Team of the Week · '.$range, $player, $photo, ['badge' => 'Premier League',
            'lines' => [$this->contributions($top['line']['goals'], $top['line']['assists']), $top['fixture']]])];
        foreach ($rows->chunk(4) as $i => $chunk) {
            if ($i > 1) {
                break;
            }
            $slides[] = $this->rowsSlide($ctx, 'Team of the Week · '.$range, $i === 0 ? 'African players on the scoresheet' : 'Also on the scoresheet',
                $chunk->map(fn ($r) => ['name' => $r['player']->name, 'line' => implode(' · ', array_filter([AfricanScope::label($r['player']), ClubName::short($r['player']->team)])),
                    'stats' => $this->contributions($r['line']['goals'], $r['line']['assists']).' v '.$r['opponent']])->values()->all());
        }
        $griot = GriotChallengeService::ready();
        $path = $griot ? route('griot.challenge', [], false) : null;
        $slides[] = $this->questionSlide($ctx, 'Team of the Week', 'Who makes next week’s team?',
            $griot ? 'Beat the Griot at matchday.africa.' : 'Call the next round at matchday.africa.');

        $names = $rows->take(3)->map(fn ($r) => $r['player']->name.' ('.$this->contributions($r['line']['goals'], $r['line']['assists']).')')->join(', ');
        $body = "Team of the Week, Premier League {$range}: {$names}.\n\nWho makes next week’s team?";

        return $this->finish($show, $slides, $body, $path, $player, $photo, '#PremierLeague', [
            'title' => "Team of the Week: {$range}", 'week_start' => data_get($show->facts, 'week_start'), 'range' => $range,
            'players' => $rows->map(fn ($r) => ['id' => $r['player']->id, 'name' => $r['player']->name, 'label' => AfricanScope::label($r['player']),
                'match_id' => $r['match_id'], 'goals' => $r['line']['goals'], 'assists' => $r['line']['assists']])->values()->all(),
        ]);
    }

    /** In-scope goal scorers and assisters in the Premier League week starting $start (Monday), best first. */
    public function totwRows(Carbon $start): Collection
    {
        $end = $start->copy()->addWeek();
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->whereHas('league', fn ($q) => $q->where('short_code', 'PL')->orWhere('espn_slug', 'eng.1'))
            ->whereIn('status', ['FINISHED', 'FT', 'AWARDED'])
            ->where('match_date', '>=', $start->format('Y-m-d H:i:s'))->where('match_date', '<', $end->format('Y-m-d H:i:s'))
            ->get();
        $rows = collect();
        foreach ($matches as $match) {
            foreach (AfricanScope::playersAt([$match->home_team_id, $match->away_team_id]) as $player) {
                $line = SlideshowFacts::playerInMatch($player, $match);
                if ($line['goals'] + $line['assists'] < 1) {
                    continue;
                }
                $home = $player->team_id === $match->home_team_id;
                $rows->push(['player' => $player, 'line' => $line, 'match_id' => $match->id,
                    'opponent' => ClubName::short($home ? $match->awayTeam : $match->homeTeam),
                    'fixture' => ClubName::short($match->homeTeam)." {$match->home_score}-{$match->away_score} ".ClubName::short($match->awayTeam),
                    'score' => $line['goals'] * 3 + $line['assists'] * 2]);
            }
        }

        return $rows->sortByDesc('score')->values();
    }

    public function weekRange(Carbon $start): string
    {
        $end = $start->copy()->addDays(6);

        return $start->format('j M').'–'.$end->format('j M');
    }

    // ---------------------------------------------------------------- slides (component cuts, drawn by App\Design\Film)

    /** Both sides' colours, flags (African national teams) or kit swatches (clubs), short names. */
    private function context(FootballMatch $match): array
    {
        $side = function ($team, string $key) {
            $nation = AfricanScope::nationForTeam($team?->name);
            $colours = SlideshowFacts::colours($team) ?? Tokens::teamFallback($key);

            return ['name' => ClubName::short($team), 'full' => ClubName::full($team), 'flag' => $nation['flag'] ?? null, 'colours' => array_values($colours)];
        };
        $home = $side($match->homeTeam, 'home');
        $away = $side($match->awayTeam, 'away');
        // Same main colour on both sides (red v red): the away side leads with its second colour.
        if (strcasecmp($home['colours'][0], $away['colours'][0]) === 0) {
            $away['colours'] = [$away['colours'][1] ?? Tokens::colour('white'), $away['colours'][0]];
        }

        return ['home' => $home, 'away' => $away];
    }

    /** One player's decks: club colours on the home side, their African country's on the away side. */
    private function playerContext(Player $player): array
    {
        $nation = AfricanScope::nation(AfricanScope::countryCode($player));
        $club = SlideshowFacts::colours($player->team) ?? Tokens::teamFallback('home');

        return [
            'home' => ['name' => ClubName::short($player->team), 'full' => ClubName::full($player->team), 'flag' => null, 'colours' => array_values($club)],
            'away' => ['name' => $nation['name'] ?? '', 'full' => $nation['name'] ?? '', 'flag' => $nation['flag'] ?? null,
                'colours' => $nation['colours'] ?? Tokens::teamFallback('away')],
        ];
    }

    private function cut(string $component, array $ctx, array $data): array
    {
        return ['component' => $component, 'sides' => $ctx] + $data;
    }

    /**
     * The opener: with a featured player, the hook card (their licensed photo or our monogram, the
     * scoreboard, a factual line); then the fixture card with the kick-off times and venue, or at
     * full time the score with the scorers.
     */
    private function openerSlides(array $ctx, FootballMatch $match, ?array $featured, ?array $photo, array $times): array
    {
        $finished = SlideshowFacts::finished($match);
        $score = $finished ? "{$match->home_score}-{$match->away_score}" : 'v';
        $out = [];
        if ($featured) {
            $p = $featured['player'];
            $side = $p->team_id === $match->away_team_id ? 'away' : 'home';
            $opp = $side === 'home' ? $ctx['away']['name'] : $ctx['home']['name'];
            $line = $finished ? "{$p->name} at full time against {$opp}" : "{$p->name} takes on {$opp}, ".explode(' ', $times['day'])[0];
            $out[] = $this->cut('hook', $ctx, ['line' => $line, 'score' => $score, 'focus' => $side,
                'player' => ['name' => $p->name, 'label' => AfricanScope::label($p), 'club' => $p->team ? ClubName::short($p->team) : null,
                    'flag' => AfricanScope::nation(AfricanScope::countryCode($p))['flag'] ?? null],
                'photo' => $this->photoFor($photo)]);
        }
        if ($finished) {
            $lines = [];
            foreach (array_slice(SlideshowFacts::goals($match), 0, 4) as $goal) {
                if ($goal['player']) {
                    $team = $goal['side'] === 'home' ? $match->homeTeam : $match->awayTeam;
                    $lines[] = trim(($goal['minute'] ?? '').' '.$goal['player'].($goal['own_goal'] ? ' (og)' : ($goal['penalty'] ? ' (pen)' : '')).', '.ClubName::short($team));
                }
            }
            $out[] = $this->cut('score_block', $ctx, ['title' => 'Full time · '.($times['date'] ?? $times['day']), 'score' => $score,
                'lines' => $lines ?: [$this->competition($match)]]);
        } else {
            $out[] = $this->cut('matchup', $ctx, ['title' => $times['date'] ?? $times['day'], 'competition' => $this->competition($match), 'stage' => null,
                'lines' => array_values(array_filter([$times['uk_long'] ?? "{$times['uk']} in the UK", $times['wat_long'] ?? $times['wat'],
                    $match->venue_name ? trim($match->venue_name.($match->venue_city ? ', '.$match->venue_city : '')) : null]))]);
        }

        return $out;
    }

    /** A player-led opener (Team of the Week, milestones): the profile card. */
    private function portraitSlide(array $ctx, string $chip, Player $player, ?array $photo, array $panel): array
    {
        $lines = [];
        foreach ($panel['lines'] as $i => $line) {
            $lines[] = ['head' => $i === 0 ? $panel['badge'] : '', 'text' => $line];
        }

        return $this->cut('profile', $ctx, ['chip' => $chip, 'name' => $player->name, 'label' => AfricanScope::label($player),
            'club' => $player->team ? ClubName::short($player->team) : null, 'flag' => AfricanScope::nation(AfricanScope::countryCode($player))['flag'] ?? null,
            'photo' => $this->photoFor($photo), 'colours' => $ctx['home']['colours'], 'lines' => $lines]);
    }

    /** The in-scope players together, with what the data says (season or match numbers). */
    private function playersSlide(array $ctx, Collection $players, string $type, ?FootballMatch $match = null): ?array
    {
        $rows = $players->take(4)->map(fn ($e) => [
            'name' => $e['player']->name,
            'line' => implode(' · ', array_filter([AfricanScope::label($e['player']), $e['player']->team ? ClubName::short($e['player']->team) : null])),
            'stats' => $this->statLine($e['match'], $e['season'], $type),
            'side' => $match && $e['player']->team_id === $match->away_team_id ? 'away' : 'home',
        ])->values()->all();
        if (!$rows) {
            return null;
        }
        $title = count($rows) === 1 ? ($type === 'preview' ? 'One to watch' : 'How '.$this->surnameOf($rows[0]['name']).' did') : ($type === 'preview' ? 'Ones to watch' : 'How they did');

        return $this->rowsSlide($ctx, $type === 'preview' ? 'African players in this match' : 'African players at full time', $title, $rows);
    }

    private function rowsSlide(array $ctx, string $chip, string $heading, array $rows): array
    {
        return $this->cut('roster', $ctx, ['title' => $chip, 'heading' => $heading, 'rows' => array_map(fn ($r) => $r + ['side' => 'home'], $rows)]);
    }

    /** The league table around the two sides: the leader and both teams, highlighted in their colours. */
    private function tableSlide(array $ctx, FootballMatch $match): ?array
    {
        $league = $match->league;
        $rows = [];
        foreach (['home' => $match->homeTeam, 'away' => $match->awayTeam] as $side => $team) {
            if ($team && ($s = SlideshowFacts::standing($team, $league))) {
                $rows[$s['position']] = ['pos' => (string) $s['position'], 'name' => ClubName::short($team), 'played' => $s['played'], 'pts' => $s['points'], 'side' => $side];
            }
        }
        if (!$rows) {
            return null;
        }
        if (!isset($rows[1]) && ($leader = \App\Models\Standing::where('league_id', $league->id)->where('position', 1)
            ->when(\App\Models\Standing::where('league_id', $league->id)->where('is_current', true)->exists(), fn ($q) => $q->where('is_current', true))
            ->orderByDesc('season_year')->with('team')->first()) && $leader->team) {
            $rows[1] = ['pos' => '1', 'name' => ClubName::short($leader->team), 'played' => $leader->matches_played, 'pts' => $leader->points, 'side' => null];
        }
        ksort($rows);

        return $this->cut('mini_table', $ctx, ['title' => $league?->name ? $league->name.' table' : 'The table', 'rows' => array_values($rows),
            'played_label' => 'P', 'points_label' => 'Pts']);
    }

    /** Each side's last five results (oldest first), when stored. */
    private function formSlide(array $ctx, FootballMatch $match): ?array
    {
        $form = ['home' => $match->homeTeam ? SlideshowFacts::form($match->homeTeam, $match) : [], 'away' => $match->awayTeam ? SlideshowFacts::form($match->awayTeam, $match) : []];

        return $form['home'] || $form['away'] ? $this->cut('form_chips', $ctx, ['title' => 'Form · last five, oldest first', 'form' => $form]) : null;
    }

    /** Last: the question, where to answer, the follow ask and the site. */
    private function ctaSlide(array $ctx, FootballMatch $match, string $question, string $cta, array $times): array
    {
        return $this->questionSlide($ctx, "{$ctx['home']['name']} v {$ctx['away']['name']} · ".explode(' ', $times['day'])[0], $question, $cta,
            $this->displayPath(route('matches.show', $match, false)));
    }

    private function questionSlide(array $ctx, string $chip, string $question, string $cta, string $url = 'matchday.africa'): array
    {
        return $this->cut('closing', $ctx, ['title' => $chip, 'question' => $question, 'cta' => $cta, 'follow' => 'Follow for more', 'url' => $url]);
    }

    /** A licensed photo for a card: only with its credit (the images rule). */
    private function photoFor(?array $photo): ?array
    {
        return $photo && !empty($photo['file']) && !empty($photo['credit'])
            ? ['file' => $photo['file'], 'crop' => $photo['crop'] ?? null, 'credit' => $photo['credit']] : null;
    }

    private function surnameOf(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        return (string) end($parts);
    }

    // ---------------------------------------------------------------- copy

    /** "2 goals · 1 assist · 90 min", "Started", "3 PL goals this season"; only numbers that are stored. */
    private function statLine(array $here, ?array $season, string $type): ?string
    {
        $bits = [];
        if ($type !== 'preview') {
            if ($here['goals']) {
                $bits[] = $here['goals'].' '.Str::plural('goal', $here['goals']);
            }
            if ($here['assists']) {
                $bits[] = $here['assists'].' '.Str::plural('assist', $here['assists']);
            }
            if ($here['minutes'] !== null) {
                $bits[] = $here['minutes'].' min';
            } elseif ($here['starter'] !== null) {
                $bits[] = $here['starter'] ? 'Started' : 'On the bench';
            }
            if ($here['rating'] !== null) {
                $bits[] = 'Rating '.number_format($here['rating'], 1);
            }
        }
        if ($season && ($season['goals'] || $season['assists']) && ($type === 'preview' || $type === 'milestone')) {
            $bits[] = $season['goals'].' '.Str::plural('goal', $season['goals']).', '.$season['assists'].' '.Str::plural('assist', $season['assists']).' in the league this season';
        }

        return $bits ? implode(' · ', $bits) : null;
    }

    private function contributions(int $goals, int $assists): string
    {
        return implode(', ', array_filter([
            $goals ? $goals.' '.Str::plural('goal', $goals) : null,
            $assists ? $assists.' '.Str::plural('assist', $assists) : null,
        ]));
    }

    /**
     * Captions for every channel (link tagged per channel, music credit, two hashtags) and the
     * record's fields.
     */
    private function finish(Slideshow $show, array $slides, string $body, ?string $path, ?Player $player, ?array $photo, string $tag, array $facts): array
    {
        $slides = array_values(array_slice($slides, 0, 6));
        $music = Slideshows::music();
        $credit = Slideshows::musicCredit($music);
        $fullCredit = Slideshows::musicCredit($music, full: true);
        $photoCredit = $photo['credit'] ?? null;
        $path ??= route('slideshows.show', $show, false);
        $tags = trim(config('slideshow.hashtag', '#MatchdayAfrica').' '.$tag);
        $content = "{$show->type}-{$show->id}";
        $link = fn (string $channel) => SocialLink::to($path, $channel, 'slideshow', $content);
        $body = SocialQueue::plain($body);
        $long = fn (string $channel) => trim("{$body}\n\n{$link($channel)}\n\n{$fullCredit}".($photoCredit ? "\nPhoto: {$photoCredit}" : '')."\n\n{$tags}");
        $captions = [
            'x' => XText::compose($body, $link('x'), "{$credit}\n{$tags}"),
            'instagram' => $long('instagram'),
            'tiktok' => $long('tiktok'),
            'youtube' => $long('youtube'),
        ];

        return [
            'facts' => $facts + ['type' => $show->type, 'built_at' => now('UTC')->toIso8601ZuluString()],
            'slides' => $slides,
            'caption' => $captions['instagram'],
            'captions' => $captions,
            'landing_path' => $path,
            'player_id' => $player?->id,
            'photo' => $photo ? array_diff_key($photo, ['file' => 1]) : null,
            'music' => $music,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /** National teams in the match that are African nations, or null for a club match. */
    private function nations(FootballMatch $match): ?array
    {
        $found = array_filter([AfricanScope::nationForTeam($match->homeTeam?->name), AfricanScope::nationForTeam($match->awayTeam?->name)]);

        return $found ? array_values($found) : null;
    }

    private function competition(FootballMatch $match): string
    {
        return (string) ($match->league?->name ?: 'Football');
    }

    public function competitionTag(FootballMatch $match): string
    {
        $tags = (array) config('buffer.x.competition_tags');
        $tag = $tags[$match->league?->espn_slug] ?? $tags[$match->league?->short_code] ?? null;
        if (!$tag && $match->league?->name) {
            $tag = '#'.preg_replace('/[^A-Za-z0-9]/', '', Str::studly(Str::ascii($match->league->name)));
        }

        return $tag && $tag !== '#' ? $tag : '';
    }

    private function matchFacts(FootballMatch $match): array
    {
        return array_filter([
            'id' => $match->id, 'home' => ClubName::full($match->homeTeam), 'away' => ClubName::full($match->awayTeam),
            'competition' => $match->league?->name, 'status' => $match->status, 'kickoff' => SlideshowFacts::times($match),
            'score' => SlideshowFacts::finished($match) ? "{$match->home_score}-{$match->away_score}" : null,
            'venue' => $match->venue_name,
        ], fn ($v) => $v !== null);
    }

    private function playerFacts(Collection $players, FootballMatch $match): array
    {
        return $players->map(fn ($e) => [
            'id' => $e['player']->id, 'name' => $e['player']->name, 'label' => AfricanScope::label($e['player']),
            'national' => AfricanScope::isNational($e['player']), 'club' => $e['player']->team?->name,
            'match' => $e['match'], 'season' => $e['season'],
        ])->values()->all();
    }

    private function clubOf(Player $player, FootballMatch $match): string
    {
        return ClubName::full($player->team_id === $match->away_team_id ? $match->awayTeam : $match->homeTeam);
    }

    private function opponentOf(Player $player, FootballMatch $match): string
    {
        return ClubName::full($player->team_id === $match->away_team_id ? $match->homeTeam : $match->awayTeam);
    }

    private function short($team): string
    {
        return ClubName::short($team);
    }

    private function surname(Player $player): string
    {
        $parts = preg_split('/\s+/', trim($player->name));

        return (string) end($parts);
    }

    private function playerPath(Player $player, FootballMatch $match): string
    {
        return \App\Services\PlayerPages::landing($player, $match);
    }

    private function displayPath(string $path): string
    {
        return 'matchday.africa'.(rtrim(parse_url($path, PHP_URL_PATH) ?: '', '/'));
    }

    private function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }
}
