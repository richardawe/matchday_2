<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\Player;
use App\Services\Slideshow\CommonsPhotos;
use App\Services\Slideshow\SlideshowPicker;
use Illuminate\Console\Command;

/**
 * Nightly: fills missing player photos from Wikimedia Commons for every player at clubs playing in
 * the next few days (Premier League first), so match stories open on a photo. Same lookup and licence
 * check as the stories (Wikidata image, else a Commons file named after the player; CC0, PD, CC BY,
 * CC BY-SA; 700 px or wider). Players already checked wait for their retry date; lookups are paced.
 */
class PlayerCommonsPhotos extends Command
{
    protected $signature = 'players:commons-photos {--days=3 : Matches in the next N days} {--limit=80 : At most this many lookups} {--pause=1000 : Milliseconds between lookups}';

    protected $description = 'Fill missing player photos from Wikimedia Commons for players in upcoming matches';

    public function handle(CommonsPhotos $photos): int
    {
        if (!config('slideshow.commons.enabled')) {
            $this->line('Commons lookups are off (SLIDESHOW_COMMONS).');

            return self::SUCCESS;
        }
        $teams = FootballMatch::with('league')->whereNotIn('status', ['FINISHED', 'FT', 'AWARDED', 'CANCELLED', 'POSTPONED'])
            ->where('match_date', '>', now('UTC')->format('Y-m-d H:i:s'))
            ->where('match_date', '<=', now('UTC')->addDays(max(1, (int) $this->option('days')))->format('Y-m-d H:i:s'))
            ->get()
            ->sortByDesc(fn ($m) => SlideshowPicker::band($m->league))
            ->flatMap(fn ($m) => [$m->home_team_id, $m->away_team_id])->unique()->values()->all();
        $retry = (int) config('slideshow.commons.retry_days', 14);
        $players = Player::whereIn('team_id', $teams)->active()->get()
            ->filter(function (Player $p) use ($retry) {
                $photo = data_get($p->metadata, 'commons_photo');
                if (!is_array($photo)) {
                    return true;
                }
                if (($photo['status'] ?? null) === 'ok') {
                    return !is_file(storage_path('app/'.($photo['path'] ?? '')));
                }

                return now()->diffInDays($photo['checked_at'] ?? now()->subYear(), true) >= $retry;
            })
            ->sortBy(fn (Player $p) => array_search($p->team_id, $teams, true))
            ->take(max(1, (int) $this->option('limit')));
        $found = 0;
        foreach ($players->values() as $i => $player) {
            if ($i > 0) {
                usleep(max(0, (int) $this->option('pause')) * 1000); // Be gentle with Wikimedia.
            }
            $found += $photos->forPlayer($player) ? 1 : 0;
        }
        $this->info("Player photos: {$found} found of {$players->count()} looked up (".count($teams).' clubs).');

        return self::SUCCESS;
    }
}
