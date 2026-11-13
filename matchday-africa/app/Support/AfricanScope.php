<?php

namespace App\Support;

use App\Http\Controllers\DiscoveryController;
use App\Models\Player;
use App\Models\PlayerHeritage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Who Matchday Africa covers: players with an African nationality (DiscoveryController::AFRICA)
 * and players with an admin-confirmed African heritage record (country + public source).
 * Pending or rejected heritage never counts and never reaches copy. Heritage is never inferred
 * from a name or appearance.
 */
class AfricanScope
{
    /** @return list<string> FIFA-style nationality codes that count as African. */
    public static function codes(): array
    {
        return DiscoveryController::AFRICA;
    }

    public static function isAfricanCode(?string $code): bool
    {
        return $code !== null && in_array(strtoupper($code), self::codes(), true);
    }

    public static function heritageReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('player_heritages');
    }

    /** Players in scope: African nationality, or a confirmed heritage row. */
    public static function scope(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('nationality_code', self::codes());
            if (self::heritageReady()) {
                $q->orWhereHas('heritages', fn (Builder $h) => $h->confirmed()
                    ->whereIn('country_code', array_keys((array) config('slideshow.nations'))));
            }
        });
    }

    /** Active in-scope players at these clubs, with their heritage rows loaded. */
    public static function playersAt(array $teamIds): Collection
    {
        $teamIds = array_values(array_filter($teamIds));
        if (!$teamIds) {
            return collect();
        }
        $query = Player::with('team')->active()->whereIn('team_id', $teamIds);
        if (self::heritageReady()) {
            $query->with('heritages');
        }

        return self::scope($query)->get();
    }

    /** Every confirmed, sourced heritage row for an African country (internationals first). */
    public static function confirmedHeritages(Player $player): \Illuminate\Support\Collection
    {
        if (!self::heritageReady()) {
            return collect();
        }
        $rows = $player->relationLoaded('heritages') ? $player->heritages : $player->heritages()->get();

        return $rows->filter(fn (PlayerHeritage $h) => $h->isConfirmed() && self::nation($h->country_code))
            ->sortBy(fn (PlayerHeritage $h) => [($h->kind ?? 'descent') === 'international' ? 0 : 1, $h->id])->values();
    }

    public static function confirmedHeritage(Player $player): ?PlayerHeritage
    {
        return self::confirmedHeritages($player)->first();
    }

    /** A confirmed row saying the player is a senior international for an African country (stored nationality aside). */
    public static function internationalHeritage(Player $player): ?PlayerHeritage
    {
        return self::confirmedHeritages($player)->first(fn (PlayerHeritage $h) => ($h->kind ?? 'descent') === 'international');
    }

    public static function inScope(Player $player): bool
    {
        return self::isAfricanCode($player->nationality_code) || self::confirmedHeritage($player) !== null;
    }

    /** True for an African national or a sourced African international; false when in scope only through descent. */
    public static function isNational(Player $player): bool
    {
        return self::isAfricanCode($player->nationality_code) || self::internationalHeritage($player) !== null;
    }

    /**
     * How copy may describe the player's African link: "Nigeria" for a Nigerian national or a sourced
     * Nigeria international, "of Nigerian descent" (or "of Congolese and Angolan descent") only from
     * confirmed descent rows. Null when out of scope.
     */
    public static function label(Player $player): ?string
    {
        if (self::isAfricanCode($player->nationality_code)) {
            return self::nation($player->nationality_code)['name'] ?? ($player->nationality ?: null);
        }
        if ($international = self::internationalHeritage($player)) {
            return self::nation($international->country_code)['name'] ?? $international->country;
        }
        $demonyms = self::confirmedHeritages($player)->map(fn (PlayerHeritage $h) => self::nation($h->country_code)['demonym'] ?? $h->country)->unique()->values();
        if ($demonyms->isEmpty()) {
            return null;
        }

        return 'of '.($demonyms->count() > 1 ? $demonyms->slice(0, -1)->join(', ').' and '.$demonyms->last() : $demonyms->first()).' descent';
    }

    /** The FIFA code of the player's African country (nationality first, then a sourced international row, then descent). */
    public static function countryCode(Player $player): ?string
    {
        if (self::isAfricanCode($player->nationality_code)) {
            return strtoupper((string) $player->nationality_code);
        }

        return self::confirmedHeritage($player)?->country_code;
    }

    /** ['code', 'name', 'demonym', 'colours', 'iso', 'flag' (path or null)] for an African FIFA code. */
    public static function nation(?string $code): ?array
    {
        $code = strtoupper((string) $code);
        $row = config("slideshow.nations.{$code}");
        if (!$row) {
            return null;
        }
        $iso = Player::isoCode($code);
        $flag = $iso ? resource_path('flags/'.strtolower($iso).'.png') : null;

        return ['code' => $code, 'name' => $row[0], 'demonym' => $row[1], 'colours' => $row[2], 'iso' => $iso,
            'flag' => $flag && is_file($flag) ? $flag : null];
    }

    /** The African nation a national team's name stands for ("Nigeria", "Ivory Coast", "DR Congo"), or null. */
    public static function nationForTeam(?string $name): ?array
    {
        $key = self::normal((string) $name);
        if ($key === '') {
            return null;
        }
        foreach ((array) config('slideshow.nations') as $code => $row) {
            $names = array_map([self::class, 'normal'], array_merge([$row[0]], $row[3] ?? []));
            if (in_array($key, $names, true)) {
                return self::nation($code);
            }
        }

        return null;
    }

    private static function normal(string $name): string
    {
        return trim(preg_replace('/[^a-z ]+/', '', Str::lower(Str::ascii($name))));
    }
}
