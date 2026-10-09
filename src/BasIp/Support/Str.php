<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * String helpers: safe output, slugs, masking, transliteration and money/date formatting.
 */
final class Str
{
    /** HTML-escape for use in text nodes and double-quoted attributes. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars(self::stringify($value), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return (string) $value;
    }

    /** Tolerant truthy check used for filters coming from query strings. */
    public static function toBoolSafe(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'y', 't', 'да'], true);
    }

    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    public static function trim(string $value): string
    {
        return trim(preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', ' ', $value) ?? $value);
    }

    public static function squash(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || str_starts_with($haystack, $needle);
    }

    public static function endsWith(string $haystack, string $needle): bool
    {
        return $needle === '' || str_ends_with($haystack, $needle);
    }

    public static function contains(string $haystack, string $needle, bool $ignoreCase = false): bool
    {
        if ($needle === '') {
            return true;
        }

        return $ignoreCase
            ? mb_stripos($haystack, $needle) !== false
            : str_contains($haystack, $needle);
    }

    public static function limit(string $value, int $limit = 100, string $end = '…'): string
    {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . $end;
    }

    public static function mask(string $value, int $keepStart = 2, int $keepEnd = 2, string $mask = '•'): string
    {
        $len = mb_strlen($value, 'UTF-8');
        if ($len <= $keepStart + $keepEnd) {
            return str_repeat($mask, max(0, $len));
        }

        return mb_substr($value, 0, $keepStart, 'UTF-8')
            . str_repeat($mask, $len - $keepStart - $keepEnd)
            . mb_substr($value, -$keepEnd, null, 'UTF-8');
    }

    /**
     * Mask an e-mail for logs and audit trails: `admin@example.com` → `ad***@example.com`.
     * Keeps enough to recognise the address, hides the rest (152-FZ).
     */
    public static function maskEmail(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || !str_contains($email, '@')) {
            return self::mask($email, 1, 1);
        }
        [$name, $domain] = explode('@', $email, 2);
        $visible = mb_substr($name, 0, min(2, mb_strlen($name, 'UTF-8')), 'UTF-8');

        return $visible . '***@' . $domain;
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = self::transliterate($value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', $separator, $value) ?? '';

        return trim($value, $separator);
    }

    /** Russian / Ukrainian / Belarusian aware transliteration. */
    public static function transliterate(string $value): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
            'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g', 'ў' => 'u',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E',
            'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
            'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
            'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '',
            'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'І' => 'I', 'Ї' => 'Yi', 'Є' => 'Ye', 'Ґ' => 'G', 'Ў' => 'U',
        ];

        return strtr($value, $map);
    }

    public static function random(int $length = 32, string $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'): string
    {
        $max  = strlen($alphabet) - 1;
        $out  = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    public static function randomHex(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Short human-friendly code, e.g. "7K4M-Q2XD". */
    public static function friendlyCode(int $groups = 2, int $groupLength = 4, string $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'): string
    {
        $parts = [];
        for ($g = 0; $g < $groups; $g++) {
            $parts[] = self::random($groupLength, $alphabet);
        }

        return implode('-', $parts);
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function snake(string $value, string $separator = '_'): string
    {
        $value = preg_replace('/(.)(?=[A-Z])/u', '$1' . $separator, $value) ?? $value;

        return mb_strtolower(str_replace(['-', ' '], $separator, $value), 'UTF-8');
    }

    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /** Human-readable byte size. */
    public static function bytes(int|float $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max((float) $bytes, 0.0);
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }

    /** Parse "128M", "2G", "-1" into bytes. */
    public static function parseBytes(string|int $value): int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? PHP_INT_MAX : 0;
        }
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTPE]?)(i?)B?$/i', $value, $m)) {
            return (int) $value;
        }
        $map  = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5, 'E' => 6];
        $unit = strtoupper($m[2]);
        $base = ($m[3] !== '' || $unit === '') ? 1024 : 1000;
        if ($unit === '') {
            $base = 1;
        }

        return (int) ((float) $m[1] * ($base ** ($map[$unit] ?? 0)));
    }

    public static function plural(int|float $count, string $one, string $few, string $many): string
    {
        $n = abs((int) $count) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }

        return $many;
    }

    /** Format money using a non-breaking thin space as the thousands separator. */
    public static function money(float|int|string|null $amount, string $symbol = '₽', int $precision = 2): string
    {
        $value = number_format((float) $amount, $precision, ',', "\u{00A0}");
        if ($precision > 0 && str_ends_with($value, ',' . str_repeat('0', $precision))) {
            $value = substr($value, 0, -(1 + $precision));
        }

        return $value . ($symbol !== '' ? "\u{00A0}" . $symbol : '');
    }

    public static function phone(string $value): string
    {
        return Phone::format($value);
    }

    /** Highlight search needles inside already-escaped HTML. */
    public static function highlight(string $escapedText, string $needle): string
    {
        $needle = trim($needle);
        if ($needle === '') {
            return $escapedText;
        }

        return (string) preg_replace(
            '/(' . preg_quote(self::e($needle), '/') . ')/iu',
            '<mark class="bg-yellow-200/70 dark:bg-yellow-500/30 text-inherit rounded-[2px] px-0.5">$1</mark>',
            $escapedText
        );
    }

    /** Extract the first N initials, e.g. "Иванов Иван Иванович" → "Иванов И.И." */
    public static function initials(string $fullName, int $count = 2): string
    {
        $parts = preg_split('/\s+/u', self::squash($fullName)) ?: [];
        $last  = array_shift($parts) ?? '';
        $out   = $last;
        $initials = '';
        foreach (array_slice($parts, 0, $count) as $part) {
            if ($part !== '') {
                // Compact form ("Иванов И.И.") fits narrow table cells better
                // than the spaced variant.
                $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') . '.';
            }
        }

        return trim($out . ($initials !== '' ? ' ' . $initials : ''));
    }

    /** Take up to two unicode words for avatar initials. */
    public static function avatarInitials(string $value): string
    {
        $parts = preg_split('/\s+/u', self::squash($value)) ?: [];
        $out   = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $out .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
        }

        return $out !== '' ? $out : '?';
    }
}
