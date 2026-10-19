<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Counts requests to Buffer's API per calendar month (UTC), because the free plan stops at 3,000
 * a month and finding out from a refused post is too late. Stored in `buffer_usage` so the count
 * survives deploys and cache clears. Limits: config('buffer.budget').
 */
final class BufferUsage
{
    public static function month(): string
    {
        return now('UTC')->format('Ym');
    }

    public static function used(): int
    {
        return self::ready() ? (int) DB::table('buffer_usage')->where('month', self::month())->value('requests') : 0;
    }

    public static function record(): void
    {
        if (!self::ready()) {
            return;
        }
        $month = self::month();
        DB::table('buffer_usage')->insertOrIgnore(['month' => $month, 'requests' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('buffer_usage')->where('month', $month)->update(['requests' => DB::raw('requests + 1'), 'updated_at' => now()]);
    }

    /** Posting (`$essential`) may go up to the hard limit; everything else stops at the soft one. */
    public static function allows(bool $essential): bool
    {
        return self::used() < (int) config($essential ? 'buffer.budget.hard_limit' : 'buffer.budget.soft_limit');
    }

    /** @return array{used: int, limit: int, soft: int, hard: int} */
    public static function summary(): array
    {
        return [
            'used' => self::used(),
            'limit' => (int) config('buffer.budget.monthly_limit'),
            'soft' => (int) config('buffer.budget.soft_limit'),
            'hard' => (int) config('buffer.budget.hard_limit'),
        ];
    }

    private static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('buffer_usage');
    }
}
