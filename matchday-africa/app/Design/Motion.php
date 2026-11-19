<?php

namespace App\Design;

/**
 * The motion rules: one easing curve (a cubic Bézier from tokens motion.ease, the same curve the
 * reel uses) and the progress of an entrance at a given time.
 */
final class Motion
{
    private static ?array $curve = null;

    /** Eased progress 0..1 on the standard curve. */
    public static function ease(float $p): float
    {
        $p = max(0.0, min(1.0, $p));
        if ($p <= 0.0 || $p >= 1.0) {
            return $p;
        }
        [$x1, $y1, $x2, $y2] = self::$curve ??= array_map('floatval', (array) Tokens::motion('ease'));
        // Solve x(t) = p by Newton's method, then return y(t).
        $t = $p;
        for ($i = 0; $i < 8; $i++) {
            $x = self::bezier($t, $x1, $x2) - $p;
            $d = self::slope($t, $x1, $x2);
            if (abs($x) < 1e-5 || abs($d) < 1e-6) {
                break;
            }
            $t = max(0.0, min(1.0, $t - $x / $d));
        }

        return self::bezier($t, $y1, $y2);
    }

    /** Raw (uneased) entrance progress of something that starts at $delay and takes $length seconds. */
    public static function progress(float $t, float $delay, ?float $length = null): float
    {
        $length ??= (float) Tokens::motion('in');

        return max(0.0, min(1.0, ($t - $delay) / max(0.01, $length)));
    }

    private static function bezier(float $t, float $a, float $b): float
    {
        return 3 * $a * (1 - $t) ** 2 * $t + 3 * $b * (1 - $t) * $t ** 2 + $t ** 3;
    }

    private static function slope(float $t, float $a, float $b): float
    {
        return 3 * $a * (1 - $t) ** 2 + 6 * ($b - $a) * (1 - $t) * $t + 3 * (1 - $b) * $t ** 2;
    }
}
