<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * Money helpers.
 *
 * Amounts are handled as integers in minor units internally wherever it matters
 * (splitting a bill across N residents) to avoid the classic
 * `100.00 / 3 = 33.333…` floating point drift. The remainder is distributed one
 * minor unit at a time so the parts always sum up to the exact total.
 */
final class Money
{
    public static function parse(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $search  = ["\u{00A0}", "\u{202F}", "\u{2009}", ' ', ',', "\t"];
        $replace = ['', '', '', '', '.', ''];
        $normalized = str_replace($search, $replace, (string) $value);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '0';

        return (int) round(((float) $normalized) * 100);
    }

    /** Minor units → decimal string with the requested precision. */
    public static function fromMinor(int $minor, int $precision = 2): string
    {
        return number_format($minor / (10 ** $precision), $precision, '.', '');
    }

    public static function toFloat(int $minor, int $precision = 2): float
    {
        return round($minor / (10 ** $precision), $precision);
    }

    public static function round(float $value, string $mode = 'half_up', int $precision = 2): float
    {
        $factor = 10 ** $precision;

        return match ($mode) {
            'up'        => ceil($value * $factor) / $factor,
            'down'      => floor($value * $factor) / $factor,
            'half_even' => round($value, $precision, PHP_ROUND_HALF_EVEN),
            'half_down' => round($value, $precision, PHP_ROUND_HALF_DOWN),
            default     => round($value, $precision, PHP_ROUND_HALF_UP),
        };
    }

    /**
     * Split `$totalMinor` across `$parts` as evenly as possible.
     * The first `$remainder` shares get +1 minor unit.
     *
     * @return array<int, int>
     */
    public static function split(int $totalMinor, int $parts, bool $largestFirst = true): array
    {
        if ($parts <= 0) {
            return [];
        }
        $sign      = $totalMinor < 0 ? -1 : 1;
        $total     = abs($totalMinor);
        $base      = intdiv($total, $parts);
        $remainder = $total % $parts;

        $out = array_fill(0, $parts, $base * $sign);
        for ($i = 0; $i < $remainder; $i++) {
            $idx = $largestFirst ? $i : $parts - 1 - $i;
            $out[$idx] += $sign;
        }

        return $out;
    }

    /**
     * Weighted split (allocation by share / persons / area).
     *
     * @param array<int|float> $weights
     * @return array<int, int>
     */
    public static function splitWeighted(int $totalMinor, array $weights): array
    {
        $weights = array_values(array_map(static fn ($w) => max(0.0, (float) $w), $weights));
        $sum     = array_sum($weights);
        if ($sum <= 0.0 || $weights === []) {
            return self::split($totalMinor, count($weights));
        }

        $allocated = [];
        $rest      = [];
        $given     = 0;
        foreach ($weights as $i => $w) {
            $exact        = $totalMinor * ($w / $sum);
            $floored      = (int) floor($exact);
            $allocated[$i] = $floored;
            $rest[$i]      = $exact - $floored;
            $given        += $floored;
        }

        // Distribute the leftover by the largest fractional part (Hamilton method).
        arsort($rest);
        $leftover = $totalMinor - $given;
        foreach (array_keys($rest) as $i) {
            if ($leftover <= 0) {
                break;
            }
            $allocated[$i]++;
            $leftover--;
        }

        return $allocated;
    }

    public static function format(float|int|string|null $amount, string $symbol = '₽', int $precision = 2): string
    {
        return Str::money($amount, $symbol, $precision);
    }

    public static function sign(float|int $amount): string
    {
        return $amount > 0 ? '+' : ($amount < 0 ? '−' : '');
    }
}
