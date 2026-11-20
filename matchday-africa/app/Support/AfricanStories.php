<?php

namespace App\Support;

use App\Models\Blog;
use App\Models\League;
use App\Models\Player;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Whether a news story is about African football, for the homepage's African-first order: African
 * competitions and clubs, African national teams (names, demonyms, nicknames), and players in
 * AfricanScope (African nationality or confirmed African heritage). An editor can also say so in
 * the article's metadata (african: true, or region: africa). Only used to order stories; it never
 * labels a player's heritage.
 */
class AfricanStories
{
    /** Competitions, clubs and team nicknames that only appear in African football stories. */
    private const TERMS = [
        'africa', 'african', 'afcon', 'africa cup of nations', 'african nations championship', 'caf', 'caf champions league',
        'caf confederation cup', 'npfl', 'nigeria premier football league', 'premier soccer league', 'betway premiership', 'dstv premiership',
        'ghana premier league', 'botola', 'kenyan premier league', 'super eagles', 'super falcons', 'black stars', 'bafana bafana',
        'banyana banyana', 'pharaohs', 'atlas lions', 'lions of teranga', 'teranga lions', 'indomitable lions', 'the elephants',
        'harambee stars', 'desert foxes', 'eagles of carthage', 'chipolopolo', 'blue sharks', 'taifa stars', 'al ahly', 'zamalek', 'mamelodi sundowns', 'orlando pirates', 'kaizer chiefs',
        'esperance', 'wydad', 'raja casablanca', 'enyimba', 'rivers united', 'asante kotoko', 'hearts of oak', 'tp mazembe', 'simba sc',
        'young africans', 'yanga', 'gor mahia', 'rs berkane', 'pyramids fc',
    ];

    public static function isAfrican(Blog $blog): bool
    {
        $meta = (array) $blog->metadata;
        if (filter_var($meta['african'] ?? false, FILTER_VALIDATE_BOOL) || strtolower((string) ($meta['region'] ?? '')) === 'africa') {
            return true;
        }

        return self::mentionsAfrica(implode(' ', [$blog->title, $blog->excerpt, implode(' ', (array) ($meta['tags'] ?? []))]));
    }

    public static function mentionsAfrica(string $text): bool
    {
        $words = preg_split('/\s+/', self::normal($text), -1, PREG_SPLIT_NO_EMPTY);
        $terms = self::terms();
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $phrase = '';
            for ($n = 0; $n < 5 && $i + $n < $count; $n++) {
                $phrase = ltrim($phrase.' '.$words[$i + $n]);
                if (isset($terms[$phrase])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, true> lower-case ASCII phrases (one to five words). */
    public static function terms(): array
    {
        return Cache::remember('african-stories:terms', 600, function () {
            $terms = self::TERMS;
            foreach ((array) config('slideshow.nations') as $row) {
                $terms = array_merge($terms, [$row[0], $row[1]], $row[3] ?? []);
            }
            $african = collect(config('competitions.order', []))->filter(fn ($region) => $region === 'africa')->keys()->all();
            try {
                $terms = array_merge($terms, League::whereIn('espn_slug', $african)->orWhereIn('short_code', $african)->pluck('name')->all());
                foreach (AfricanScope::scope(Player::query()->active())->limit(5000)->pluck('name') as $name) {
                    $terms[] = $name;
                    // Headlines use surnames ("Salah double"). Short surnames are too often ordinary words.
                    $last = Str::afterLast(trim((string) $name), ' ');
                    if (mb_strlen($last) >= 5 && $last !== trim((string) $name)) {
                        $terms[] = $last;
                    }
                }
            } catch (\Throwable) {
                // Leagues and players are a bonus; the fixed terms still work.
            }

            return collect($terms)->map(fn ($t) => self::normal((string) $t))->filter()->unique()->mapWithKeys(fn ($t) => [$t => true])->all();
        });
    }

    private static function normal(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii($text)))));
    }
}
