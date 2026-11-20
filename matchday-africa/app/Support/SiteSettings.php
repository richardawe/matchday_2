<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches an admin flips at /admin/settings, stored in site_settings. A key nobody has set falls back
 * to its default in config (so .env decides until an admin changes it). Cached for a minute.
 */
class SiteSettings
{
    public const AGENT_CAN_PUBLISH = 'agent_can_publish';

    private const CACHE_PREFIX = 'site-settings:';

    public static function ready(): bool
    {
        return Schema::hasTable('site_settings');
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::ready() ? Cache::remember(self::CACHE_PREFIX.$key, 60,
            fn () => DB::table('site_settings')->where('key', $key)->value('value') ?? '') : '';

        return $value === '' ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function set(string $key, bool|string $value, ?int $userId = null): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : $value;
        $now = now();
        if (DB::table('site_settings')->where('key', $key)->exists()) {
            DB::table('site_settings')->where('key', $key)->update(['value' => $value, 'updated_by' => $userId, 'updated_at' => $now]);
        } else {
            DB::table('site_settings')->insert(['key' => $key, 'value' => $value, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        }
        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /** The agent may publish its own articles (AGENT_CAN_PUBLISH in .env, default true, unless an admin switched it). */
    public static function agentCanPublish(): bool
    {
        return self::bool(self::AGENT_CAN_PUBLISH, (bool) config('agent.can_publish', true));
    }
}
