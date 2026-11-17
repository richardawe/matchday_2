<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daily visit counts by UTM tags (table utm_visits) for /api/agent/analytics. A visit is a page
 * load carrying utm_source, counted once per session for the same source, medium and campaign.
 * Bots are skipped. Counting starts when the migration runs; earlier visits live in Clarity only.
 */
class UtmVisits
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless/i';

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('utm_visits');
    }

    public static function count(Request $request): void
    {
        $source = Str::limit(trim(Str::lower((string) $request->query('utm_source'))), 100, '');
        if ($source === '' || !is_string($request->query('utm_source')) || preg_match(self::BOTS, (string) $request->userAgent())) {
            return;
        }
        $medium = Str::limit(trim(Str::lower((string) (is_string($request->query('utm_medium')) ? $request->query('utm_medium') : ''))), 100, '');
        $campaign = Str::limit(trim(Str::lower((string) (is_string($request->query('utm_campaign')) ? $request->query('utm_campaign') : ''))), 150, '');
        $key = 'md_utm_counted.'.md5("{$source}|{$medium}|{$campaign}");
        if ($request->hasSession() && $request->session()->has($key)) {
            return;
        }
        try {
            if (!self::ready()) {
                return;
            }
            $day = now()->toDateString();
            $where = ['day' => $day, 'utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => $campaign];
            $updated = DB::table('utm_visits')->where($where)->increment('visits', 1, ['updated_at' => now()]);
            if (!$updated) {
                DB::table('utm_visits')->insertOrIgnore($where + ['visits' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($request->hasSession()) {
                $request->session()->put($key, true);
            }
        } catch (\Throwable) {
            // Counting never breaks a page.
        }
    }
}
