<?php

namespace App\Design;

use RuntimeException;

/**
 * The design language's tokens (resources/design/tokens.json): colours, fonts, the type scale,
 * the 1080x1920 grid and safe zone, motion timings, the music bed, image and copy rules.
 * Every video and card renderer reads its colours, fonts, sizes and timings from here; the
 * Remotion reel imports the same JSON (reel/src/design.js). See docs/design-language.md.
 */
final class Tokens
{
    private static ?array $tokens = null;

    public static function path(): string
    {
        return resource_path('design/tokens.json');
    }

    public static function all(): array
    {
        if (self::$tokens === null) {
            $data = json_decode((string) file_get_contents(self::path()), true);
            if (!is_array($data)) {
                throw new RuntimeException('resources/design/tokens.json is not valid JSON.');
            }
            self::$tokens = $data;
        }

        return self::$tokens;
    }

    /** Dotted lookup: Tokens::get('grid.safe.top'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = data_get(self::all(), $key);
        if ($value === null && func_num_args() < 2) {
            throw new RuntimeException("Unknown design token: {$key}");
        }

        return $value ?? $default;
    }

    /** A brand colour as #RRGGBB: colour('yellow'). */
    public static function colour(string $name): string
    {
        return (string) self::get("colour.{$name}");
    }

    /** Every brand colour token: ['black' => '#0A0A0A', ...]. */
    public static function colours(): array
    {
        return (array) self::get('colour');
    }

    /** The absolute path of a font token's file: font('display'). */
    public static function font(string $name): string
    {
        return base_path((string) self::get("font.{$name}.file"));
    }

    /** A type style: ['font' => token, 'file' => path, 'size' => px, 'min' => px, 'leading' => x]. */
    public static function type(string $style): array
    {
        $type = (array) self::get("type.{$style}");

        return $type + ['file' => self::font($type['font'])];
    }

    /** [width, height] of the vertical canvas. */
    public static function canvas(): array
    {
        return [(int) self::get('canvas.width'), (int) self::get('canvas.height')];
    }

    /** The text-safe area: ['top', 'bottom', 'left', 'right'] in px. */
    public static function safe(): array
    {
        return array_map('intval', (array) self::get('grid.safe'));
    }

    public static function motion(string $key): mixed
    {
        return self::get("motion.{$key}");
    }

    /** The team colour pair to use when a side has none stored. */
    public static function teamFallback(string $side): array
    {
        return (array) self::get("team_fallback.{$side}");
    }

    /** The music bed: the only track videos may use. */
    public static function musicFile(): string
    {
        return base_path((string) self::get('music.file'));
    }

    /** Forget the cached tokens (tests that swap the file). */
    public static function flush(): void
    {
        self::$tokens = null;
    }
}
