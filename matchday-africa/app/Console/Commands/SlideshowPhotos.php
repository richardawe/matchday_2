<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Services\Slideshow\CommonsPhotos;
use App\Services\Slideshow\SlideshowPicker;
use App\Services\Slideshow\Slideshows;
use App\Support\AfricanScope;
use Illuminate\Console\Command;

/**
 * Looks up slide 01 photos ahead of time for in-scope players at clubs playing in the next week,
 * Premier League clubs first, so renders don't wait on Wikidata and editors can see (and pin) the
 * gaps at /admin/slideshows before the decks are made. Players already checked are skipped until
 * their retry date.
 */
class SlideshowPhotos extends Command
{
    protected $signature = 'slideshow:photos {--limit=30 : At most this many lookups}';

    protected $description = 'Find freely licensed Commons photos for in-scope players at clubs playing in the next week (SLIDESHOW_ENABLED)';

    public function handle(CommonsPhotos $photos): int
    {
        if (!Slideshows::enabled() || !config('slideshow.commons.enabled')) {
            $this->line('Slideshows or Commons lookups are off.');

            return self::SUCCESS;
        }
        $teams = FootballMatch::with('league')->whereIn('status', ['SCHEDULED', 'TIMED', 'NS', 'PRE'])
            ->where('match_date', '>', now('UTC')->format('Y-m-d H:i:s'))
            ->where('match_date', '<=', now('UTC')->addDays(7)->format('Y-m-d H:i:s'))
            ->get()
            ->sortByDesc(fn ($m) => SlideshowPicker::band($m->league))
            ->flatMap(fn ($m) => [$m->home_team_id, $m->away_team_id])->unique()->values()->all();
        $players = AfricanScope::playersAt($teams)
            ->sortBy(fn ($p) => array_search($p->team_id, $teams, true))
            ->filter(fn ($p) => data_get($p->metadata, 'commons_photo.status') === null)
            ->take((int) $this->option('limit'));
        $found = 0;
        foreach ($players as $player) {
            $found += $photos->forPlayer($player) ? 1 : 0;
            usleep(300_000); // Be gentle with Wikimedia.
        }
        $this->info("Slide photos: {$found} found of {$players->count()} looked up.");

        return self::SUCCESS;
    }
}
