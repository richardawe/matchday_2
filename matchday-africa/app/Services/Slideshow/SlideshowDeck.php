<?php

namespace App\Services\Slideshow;

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
        $colours = $this->matchColours($match);
        $photo = $featured ? $this->photos->forPlayer($featured['player']) : null;

        $slides = [];
        $slides[] = $featured
            ? $this->hookSlide($featured, $photo, $colours, $this->competition($match), $this->previewClaim($featured, $match))
            : $this->flagsSlide($match, $national, $colours, $times);
        $slides[] = $this->fixtureSlide($match, $colours, $times);
        if ($stat = $this->playersSlide($players, $match, 'preview', $colours)) {
            $slides[] = $stat;
        }
        if ($form = $this->formSlide($match, $colours)) {
            $slides[] = $form;
        }
        $slides[] = $this->ctaSlide("Who wins: {$this->short($match->homeTeam)} or {$this->short($match->awayTeam)}?",
            'Call the score on matchday.africa', $this->displayPath(route('matches.show', $match, false)), $colours);

        $label = $featured ? AfricanScope::label($featured['player']) : null;
        $lead = $featured ? "{$featured['player']->name} ({$label}) and ".$this->clubOf($featured['player'], $match).' ' : '';
        $body = $lead
            ? $lead.'face '.$this->opponentOf($featured['player'], $match)." on {$times['day']}. Kick-off {$times['uk']}, {$times['wat']}."
            : "{$home} v {$away} on {$times['day']}. Kick-off {$times['uk']}, {$times['wat']}.";
        $body .= "\n\nWho wins: {$this->short($match->homeTeam)} or {$this->short($match->awayTeam)}? Call the score on matchday.africa.";

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
        $colours = $this->matchColours($match);
        $times = SlideshowFacts::times($match);
        $photo = $featured ? $this->photos->forPlayer($featured['player']) : null;
        [$home, $away] = [ClubName::full($match->homeTeam), ClubName::full($match->awayTeam)];
        $score = "{$home} {$match->home_score}-{$match->away_score} {$away}";

        $slides = [];
        $slides[] = $featured
            ? $this->hookSlide($featured, $photo, $colours, 'FULL TIME · '.$this->competition($match), $this->postClaim($featured, $match))
            : $this->flagsSlide($match, $national, $colours, $times);
        $slides[] = $this->fixtureSlide($match, $colours, $times, goals: SlideshowFacts::goals($match));
        if ($played->isNotEmpty() && ($stat = $this->playersSlide($played, $match, 'post_match', $colours))) {
            $slides[] = $stat;
        }
        if ($table = $this->tableSlide($match, $colours)) {
            $slides[] = $table;
        }
        $question = $featured && $featured['match']['played']
            ? "How did {$this->surname($featured['player'])} play?"
            : "What did you make of {$this->short($match->homeTeam)} v {$this->short($match->awayTeam)}?";
        $slides[] = $this->ctaSlide($question, 'Full story and stats on matchday.africa', $this->displayPath(route('matches.show', $match, false)), $colours);

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
        $colours = SlideshowFacts::colours($player->team) ?? $this->matchColours($match);
        $colours = [$colours[0], AfricanScope::nation(AfricanScope::countryCode($player))['colours'][0] ?? $colours[1]];
        $photo = $this->photos->forPlayer($player);
        $times = SlideshowFacts::times($match);
        $league = $match->league?->name ?? 'league';
        $entry = ['player' => $player, 'match' => $here, 'season' => $season];

        $slides = [
            $this->hookSlide($entry, $photo, $colours, 'MILESTONE · '.Str::upper($league), "{$goals} {$league} goals this season"),
            $this->fixtureSlide($match, $colours, $times, goals: SlideshowFacts::goals($match)),
            $this->playersSlide(collect([$entry]), $match, 'milestone', $colours),
            $this->ctaSlide("How many will {$this->surname($player)} finish the season on?", 'Follow every goal on matchday.africa',
                $this->displayPath($this->playerPath($player, $match)), $colours),
        ];
        $body = "{$player->name} (".AfricanScope::label($player).") has {$goals} {$league} goals this season after scoring in "
            .ClubName::full($match->homeTeam)." {$match->home_score}-{$match->away_score} ".ClubName::full($match->awayTeam).'.'
            ."\n\nHow many will {$this->surname($player)} finish the season on?";

        return $this->finish($show, array_values(array_filter($slides)), $body, $this->playerPath($player, $match), $player, $photo,
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
        $nation = AfricanScope::nation(AfricanScope::countryCode($player));
        $colours = [SlideshowFacts::colours($player->team)[0] ?? '#0B0B0B', $nation['colours'][0] ?? '#FFD23F'];
        $photo = $this->photos->forPlayer($player);
        $range = data_get($show->facts, 'range') ?? $this->weekRange(Carbon::parse((string) data_get($show->facts, 'week_start'), 'UTC'));

        $slides = [
            $this->hookSlide(['player' => $player, 'match' => $top['line'], 'season' => null], $photo, $colours,
                'TEAM OF THE WEEK · PREMIER LEAGUE', $this->contributions($top['line']['goals'], $top['line']['assists']).' · '.$top['fixture']),
        ];
        foreach ($rows->chunk(4) as $i => $chunk) {
            if ($i > 1) {
                break;
            }
            $slides[] = ['kind' => 'players', 'colours' => $colours, 'eyebrow' => 'TEAM OF THE WEEK · '.Str::upper($range),
                'title' => $i === 0 ? 'African players and players of African descent' : 'Also on the scoresheet',
                'rows' => $chunk->map(fn ($r) => ['name' => $r['player']->name, 'label' => AfricanScope::label($r['player']),
                    'club' => ClubName::short($r['player']->team), 'stats' => $this->contributions($r['line']['goals'], $r['line']['assists']).' v '.$r['opponent']])->values()->all()];
        }
        $griot = GriotChallengeService::ready();
        $path = $griot ? route('griot.challenge', [], false) : null;
        $slides[] = $this->ctaSlide('Who makes next week’s team?', $griot ? 'Beat the Griot on matchday.africa' : 'Call the next round on matchday.africa',
            $path ? $this->displayPath($path) : 'matchday.africa', $colours);

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

    // ---------------------------------------------------------------- slides

    private function hookSlide(array $entry, ?array $photo, array $colours, string $eyebrow, ?string $claim): array
    {
        $player = $entry['player'];
        $code = AfricanScope::countryCode($player);

        return array_filter([
            'kind' => 'hook', 'colours' => $colours, 'eyebrow' => Str::upper($eyebrow),
            'title' => $player->name, 'label' => AfricanScope::label($player),
            'club' => $player->team ? ClubName::full($player->team) : null,
            'claim' => $claim, 'flag' => AfricanScope::nation($code)['flag'] ?? null,
            'photo' => $photo['file'] ?? null, 'photo_crop' => $photo['crop'] ?? null,
            'credit' => $photo['credit'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** National-team decks without line-up data: both flags, the fixture and the kick-off. */
    private function flagsSlide(FootballMatch $match, ?array $nations, array $colours, array $times): array
    {
        $result = SlideshowFacts::finished($match) ? "{$match->home_score}-{$match->away_score}" : null;

        return array_filter([
            'kind' => 'flags', 'colours' => $colours, 'eyebrow' => Str::upper($this->competition($match)),
            'home' => ClubName::full($match->homeTeam), 'away' => ClubName::full($match->awayTeam),
            'home_flag' => AfricanScope::nationForTeam($match->homeTeam?->name)['flag'] ?? null,
            'away_flag' => AfricanScope::nationForTeam($match->awayTeam?->name)['flag'] ?? null,
            'result' => $result, 'claim' => $result ? 'Full time' : "{$times['day']} · {$times['uk']} · {$times['wat']}",
        ], fn ($v) => $v !== null);
    }

    private function fixtureSlide(FootballMatch $match, array $colours, array $times, array $goals = []): array
    {
        $finished = SlideshowFacts::finished($match);
        $lines = $finished ? [] : array_values(array_filter([
            $times['day'], "Kick-off {$times['uk']} · {$times['wat']}",
            $match->venue_name ? trim($match->venue_name.($match->venue_city ? ', '.$match->venue_city : '')) : null,
        ]));
        if ($finished) {
            foreach (array_slice($goals, 0, 6) as $goal) {
                if (!$goal['player']) {
                    continue;
                }
                $team = $goal['side'] === 'home' ? $match->homeTeam : $match->awayTeam;
                $lines[] = trim(($goal['minute'] ?? '').' '.$goal['player'].($goal['own_goal'] ? ' (og)' : ($goal['penalty'] ? ' (pen)' : '')).' · '.ClubName::short($team));
            }
            if (!$lines) {
                $lines[] = $times['day'];
            }
        }

        return [
            'kind' => 'fixture', 'colours' => $colours, 'eyebrow' => Str::upper(($finished ? 'Full time · ' : '').$this->competition($match)),
            'home' => ClubName::full($match->homeTeam), 'away' => ClubName::full($match->awayTeam),
            'result' => $finished ? "{$match->home_score}-{$match->away_score}" : null, 'lines' => $lines,
        ];
    }

    private function playersSlide(Collection $players, FootballMatch $match, string $type, array $colours): ?array
    {
        $rows = $players->take(4)->map(function ($entry) use ($type) {
            $line = $this->statLine($entry['match'], $entry['season'], $type);

            return ['name' => $entry['player']->name, 'label' => AfricanScope::label($entry['player']),
                'club' => $entry['player']->team ? ClubName::short($entry['player']->team) : null, 'stats' => $line];
        })->values()->all();
        if (!$rows) {
            return null;
        }

        return ['kind' => 'players', 'colours' => $colours,
            'eyebrow' => Str::upper($type === 'preview' ? 'In this match' : ($type === 'milestone' ? 'The numbers' : 'How they did')),
            'title' => count($rows) === 1 ? ($type === 'preview' ? 'One to watch' : 'The numbers') : 'African players and players of African descent',
            'rows' => $rows];
    }

    private function formSlide(FootballMatch $match, array $colours): ?array
    {
        $rows = [];
        foreach ([$match->homeTeam, $match->awayTeam] as $team) {
            if (!$team) {
                continue;
            }
            $form = SlideshowFacts::form($team, $match);
            $standing = SlideshowFacts::standing($team, $match->league);
            if (!$form && !$standing) {
                continue;
            }
            $rows[] = array_filter([
                'team' => ClubName::full($team),
                'form' => $form ? implode('', array_column(array_reverse($form), 'result')) : null,
                'standing' => $standing ? $this->ordinal($standing['position']).($standing['points'] !== null ? ", {$standing['points']} pts" : '') : null,
            ]);
        }

        return $rows ? ['kind' => 'form', 'colours' => $colours, 'eyebrow' => 'FORM AND TABLE', 'title' => 'Last five, oldest first', 'rows' => $rows] : null;
    }

    private function tableSlide(FootballMatch $match, array $colours): ?array
    {
        $rows = [];
        foreach ([$match->homeTeam, $match->awayTeam] as $team) {
            if ($team && ($s = SlideshowFacts::standing($team, $match->league))) {
                $rows[] = ['team' => ClubName::full($team), 'standing' => $this->ordinal($s['position']).($s['points'] !== null ? ", {$s['points']} pts" : '')
                    .($s['played'] !== null ? " from {$s['played']}" : '')];
            }
        }

        return $rows ? ['kind' => 'form', 'colours' => $colours, 'eyebrow' => 'THE TABLE', 'title' => 'Where they stand', 'rows' => $rows] : null;
    }

    private function ctaSlide(string $question, string $cta, string $path, array $colours): array
    {
        return ['kind' => 'cta', 'colours' => $colours, 'question' => $question, 'cta' => $cta, 'path' => $path];
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

    private function previewClaim(array $featured, FootballMatch $match): string
    {
        $season = $featured['season'];
        if ($season && $season['goals'] > 0) {
            return "{$season['goals']} league ".Str::plural('goal', $season['goals']).' this season';
        }
        if ($season && $season['assists'] > 0) {
            return "{$season['assists']} league ".Str::plural('assist', $season['assists']).' this season';
        }

        return $this->short($match->homeTeam).' v '.$this->short($match->awayTeam);
    }

    private function postClaim(array $featured, FootballMatch $match): string
    {
        $here = $featured['match'];
        $result = ClubName::short($match->homeTeam)." {$match->home_score}-{$match->away_score} ".ClubName::short($match->awayTeam);
        if ($here['goals'] || $here['assists']) {
            return $this->contributions($here['goals'], $here['assists'])." in {$result}";
        }

        return $result;
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

    private function matchColours(FootballMatch $match): array
    {
        $home = SlideshowFacts::colours($match->homeTeam) ?? ['#0B0B0B', '#FFD23F'];
        $away = SlideshowFacts::colours($match->awayTeam) ?? ['#FFD23F', '#0B0B0B'];
        // Same main colour on both sides (red v red): the away side shows its second colour.
        $second = strcasecmp($home[0], $away[0]) === 0 ? ($away[1] ?? '#FFFFFF') : $away[0];

        return [$home[0], $second];
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
        // Player pages show African nationals today; descent players get theirs with the player-pages work.
        return AfricanScope::isNational($player) ? route('discovery.show', $player, false) : route('matches.show', $match, false);
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
