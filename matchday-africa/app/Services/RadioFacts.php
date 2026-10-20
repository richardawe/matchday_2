<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\NewsCandidate;
use App\Models\Player;
use App\Models\RadioBulletin;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** A recording-time snapshot. Generated articles and match previews are never factual inputs. */
class RadioFacts
{
    public function collect(): array
    {
        $approved = collect(config('news.sources'))->whereIn('name', config('radio.news_sources'))->values()->all();
        if ($approved) {
            app(FootballNewsService::class)->discover($approved);
        }
        $covered = RadioBulletin::published()->where('published_at', '>=', now()->subDays(3))
            ->pluck('facts')->flatten(1)->where('kind', 'news')->values();
        $facts = [];
        $players = Player::active()->whereIn('nationality_code', \App\Http\Controllers\DiscoveryController::AFRICA)
            ->pluck('name')->map(fn ($name) => Str::lower($name))->filter()->all();
        $news = NewsCandidate::whereIn('source', array_column($approved, 'name'))
            ->whereBetween('source_published_at', [now()->subHours(24), now()])->get()
            ->sortByDesc(function ($n) use ($players) {
                $text = Str::lower($n->title.' '.$n->summary);
                $africanPlayer = collect($players)->contains(fn ($name) => str_contains($text, $name));

                return $n->selection_score + ($africanPlayer ? 120 : 0)
                    + (preg_match('/\b(afcon|caf|africa cup)\b/i', $text) ? 140 : 0);
            });
        foreach ($news as $n) {
            if (! in_array(strtolower((string) parse_url($n->source_url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                continue;
            }
            $key = 'news:'.$n->fingerprint;
            // Cluster near-identical reports across publishers and recent editions.
            if ($covered->contains('key', $key) || $this->duplicate($n->title, $covered->pluck('headline')->all())
                || $this->duplicate($n->title, array_column($facts, 'headline'))) {
                continue;
            }
            $facts[] = ['key' => $key, 'kind' => 'news', 'headline' => $n->title,
                'text' => $n->title.'. '.strip_tags((string) $n->summary), 'source' => $n->source,
                'url' => $n->source_url, 'at' => $n->source_published_at->toIso8601String(),
                'rumour' => (bool) preg_match('/\b(rumou?r|reportedly|linked|could|may|talks|interest)\b/i', $n->title.' '.$n->summary)];
            if (count($facts) >= 5) {
                break;
            }
        }

        $utc = now('UTC');
        $matches = FootballMatch::with(['homeTeam', 'awayTeam', 'league'])
            ->where(function ($q) use ($utc) {
                $q->where(function ($q) use ($utc) {
                    $q->whereIn('status', ['FINISHED', 'FT'])->whereBetween('match_date', [$utc->copy()->subHours(18), $utc]);
                })->orWhere(function ($q) use ($utc) {
                    $q->whereIn('status', ['SCHEDULED', 'TIMED'])->whereBetween('match_date', [$utc, $utc->copy()->addHours(18)]);
                });
            })->get()->sortBy(fn ($m) => [$m->league?->competition_rank ?? 999, $m->match_date->timestamp])->take(8);
        foreach ($matches as $m) {
            if (! $m->homeTeam || ! $m->awayTeam || ! $m->league) {
                continue;
            }
            $kickoff = Carbon::parse($m->match_date->format('Y-m-d H:i:s'), 'UTC')->setTimezone('Africa/Lagos');
            $fixture = in_array($m->status, ['SCHEDULED', 'TIMED'], true);
            if (! $fixture && ($m->home_score === null || $m->away_score === null)) {
                continue;
            }
            $text = $fixture
                ? "{$m->homeTeam->name} face {$m->awayTeam->name} in {$m->league->name} on ".$kickoff->format('l j F').' at '.$kickoff->format('H:i').' WAT.'
                : "{$m->homeTeam->name} {$m->home_score}, {$m->awayTeam->name} {$m->away_score}. Full time in {$m->league->name}. Played on ".$kickoff->format('l j F').'.';
            $facts[] = ['key' => 'match:'.$m->id, 'kind' => $fixture ? 'fixture' : 'result',
                'headline' => $m->homeTeam->name.' v '.$m->awayTeam->name, 'text' => $text,
                'source' => 'Matchday match centre', 'url' => route('matches.show', $m), 'at' => now()->toIso8601String(), 'rumour' => false];
        }

        return $facts;
    }

    private function duplicate(string $title, array $others): bool
    {
        $words = fn ($s) => array_unique(array_filter(preg_split('/[^\pL\pN]+/u', Str::lower($s)), fn ($w) => mb_strlen($w) > 3));
        $a = $words($title);
        foreach ($others as $other) {
            $b = $words((string) $other);
            $union = count(array_unique(array_merge($a, $b)));
            if ($union && count(array_intersect($a, $b)) / $union >= 0.65) {
                return true;
            }
        }

        return false;
    }
}
