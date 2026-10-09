<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * Phone-number normalisation.
 *
 * The system stores numbers in E.164 (`+79161234567`) so that the same person
 * is not duplicated when imported from different spreadsheets, and so that
 * SMS / Telegram / WhatsApp drivers receive a canonical value.
 */
final class Phone
{
    /** Country calling codes we know how to format nicely. */
    private const FORMATS = [
        '7'   => '+%d (%d%d%d) %d%d%d-%d%d-%d%d',   // RU/KZ
        '375' => '+%d%d%d (%d%d) %d%d%d-%d%d-%d%d', // BY
        '380' => '+%d%d%d (%d%d) %d%d%d-%d%d-%d%d', // UA
    ];

    /** Strip everything but digits and a leading `+`. */
    public static function digits(?string $value): string
    {
        $plus   = str_starts_with(trim($value), '+') ? '+' : '';
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $plus . $digits;
    }

    /**
     * Convert to E.164 when we can. Russian local numbers (`8 916 …`,
     * `916…`) are promoted to `+7…`.
     */
    public static function normalize(?string $value, string $defaultCountry = '7'): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return '';
        }
        $trimmed = ltrim($digits, '+');

        // 8-916-123-45-67 → 79161234567
        if ($defaultCountry === '7' && strlen($trimmed) === 11 && str_starts_with($trimmed, '8')) {
            $trimmed = '7' . substr($trimmed, 1);
        }
        // 9161234567 → 79161234567
        if ($defaultCountry === '7' && strlen($trimmed) === 10) {
            $trimmed = $defaultCountry . $trimmed;
        }
        // 0079161234567 → 79161234567
        if (str_starts_with($trimmed, '00')) {
            $trimmed = substr($trimmed, 2);
        }

        return '+' . $trimmed;
    }

    public static function isValid(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    /** Pretty, locale-aware formatting with a graceful fallback. */
    public static function format(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $normalized = self::normalize($value);
        $digits     = preg_replace('/\D+/', '', $normalized) ?? '';

        foreach (self::FORMATS as $cc => $mask) {
            // Numeric array keys arrive as ints — normalise before string ops.
            $cc = (string) $cc;
            if (str_starts_with($digits, $cc)) {
                $rest = substr($digits, strlen($cc));
                $args = array_map('intval', str_split($cc));
                $args = array_merge($args, array_map('intval', str_split($rest)));
                $need = substr_count($mask, '%d');
                if (count($args) === $need) {
                    return vsprintf($mask, $args);
                }
            }
        }

        return $normalized;
    }

    /** Digits-only variant, handy for `tel:` links and comparisons. */
    public static function tel(?string $value): string
    {
        return $value === null ? '' : self::normalize($value);
    }

    /** Best-effort region label. */
    public static function region(string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return match (true) {
            str_starts_with($digits, '7')   => 'RU/KZ',
            str_starts_with($digits, '375') => 'BY',
            str_starts_with($digits, '380') => 'UA',
            strlen($digits) > 1             => 'INT',
            default                         => 'LOCAL',
        };
    }
}
