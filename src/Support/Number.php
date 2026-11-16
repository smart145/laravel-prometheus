<?php

declare(strict_types=1);

namespace Smart145\Prometheus\Support;

/**
 * Converts numbers to and from the text that Prometheus and Redis read.
 *
 * PHP's `(string) $float` keeps 14 significant digits and writes `NAN` and
 * `INF`, so it loses precision and does not round-trip through Redis.
 */
final class Number
{
    /**
     * Format a value with the shortest text that reads back as the same float.
     */
    public static function format(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        if (floor($value) === $value && abs($value) < 1e15 && abs($value) < PHP_INT_MAX) {
            return (string) (int) $value;
        }

        foreach ([15, 16, 17] as $precision) {
            // %H is %G without the locale: the decimal separator is always a point.
            $text = sprintf('%.' . $precision . 'H', $value);

            if ((float) $text === $value) {
                break;
            }
        }

        return $text;
    }

    /**
     * Parse a value that format() or Redis wrote.
     */
    public static function parse(mixed $text): float
    {
        return match (strtolower(trim((string) $text))) {
            'nan' => NAN,
            '+inf', 'inf' => INF,
            '-inf' => -INF,
            default => (float) $text,
        };
    }
}
