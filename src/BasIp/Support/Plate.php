<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * Vehicle registration plate helpers.
 *
 * Russian plates (ГОСТ Р 50577-47) use only the 12 letters that have an
 * identical Latin glyph: А В Е К М Н О Р С Т У Х. Camera/ANPR systems emit the
 * **Latin** form, so the canonical internal representation is Latin too:
 *
 *     А123ВС77  →  A123BC77
 *
 * That is *not* the same as generic transliteration (which would give A123VS77
 * and never match what the camera sends). Display formatting maps back to
 * Cyrillic so humans see a familiar plate.
 *
 * Normalising on write means `а123вс77`, `А 123 ВС 77`, `A123BC77` and
 * `A-123-BC-77` all compare equal — which is what makes "open by plate" work.
 */
final class Plate
{
    /** Cyrillic → Latin, restricted to the letters allowed on RU plates. */
    private const TO_LATIN = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
        'а' => 'A', 'в' => 'B', 'е' => 'E', 'к' => 'K', 'м' => 'M', 'н' => 'H',
        'о' => 'O', 'р' => 'P', 'с' => 'C', 'т' => 'T', 'у' => 'Y', 'х' => 'X',
        // Common OCR/keyboard confusions on plates.
        'З' => '3', 'з' => '3', 'Ѕ' => 'S', 'В' => 'B',
    ];

    /** Latin → Cyrillic, for human-facing output. */
    private const TO_CYRILLIC = [
        'A' => 'А', 'B' => 'В', 'E' => 'Е', 'K' => 'К', 'M' => 'М', 'H' => 'Н',
        'O' => 'О', 'P' => 'Р', 'C' => 'С', 'T' => 'Т', 'Y' => 'У', 'X' => 'Х',
    ];

    /** The 12 Latin letters a Russian plate may contain. */
    private const RU_LATIN = 'ABEKMHOPCTYX';

    /**
     * Canonical form: uppercase Latin letters + digits, no separators.
     * Returns '' for null/empty input.
     */
    public static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = Str::squash((string) $value);
        if ($value === '') {
            return '';
        }

        // Uppercase (multibyte-safe), then map the RU letters to their Latin
        // look-alikes and drop everything that is not a letter or a digit.
        $value = Str::upper($value);
        $value = strtr($value, self::TO_LATIN);
        $value = (string) preg_replace('/[^A-Z0-9]/u', '', $value);

        // Any remaining non-ASCII letters (foreign plates in Cyrillic, typos)
        // are transliterated as a last resort so the value stays searchable.
        if (!mb_check_encoding($value, 'ASCII')) {
            $value = Str::transliterate($value);
            $value = (string) preg_replace('/[^A-Z0-9]/u', '', Str::upper($value));
        }

        return $value;
    }

    /**
     * Human-readable form.
     *
     * A Russian plate is rendered back in Cyrillic with the conventional
     * spacing (`А123ВС 77`); anything else is returned as-is.
     */
    public static function format(?string $value): string
    {
        $plate = self::normalize($value);
        if ($plate === '') {
            return '';
        }

        if (preg_match('/^([' . self::RU_LATIN . '])(\d{3})([' . self::RU_LATIN . ']{2})(\d{2,3})$/', $plate, $m)) {
            $letters = strtr($m[1] . $m[3], self::TO_CYRILLIC);

            // Conventional Russian layout: letter+digits+letters, space, region.
            return sprintf('%s%s%s %s', mb_substr($letters, 0, 1), $m[2], mb_substr($letters, 1), $m[4]);
        }

        return $plate;
    }

    public static function isValid(?string $value): bool
    {
        $plate = self::normalize($value);
        if ($plate === '') {
            return false;
        }
        if (self::isRussian($plate)) {
            return true;
        }

        // Foreign / legacy plates: 4..12 alphanumerics, at least one digit.
        return (bool) preg_match('/^[A-Z0-9]{4,12}$/', $plate) && preg_match('/\d/', $plate) === 1;
    }

    /** True for the ГОСТ Р 50577-47 pattern (2 or 3 digit region). */
    public static function isRussian(?string $value): bool
    {
        $plate = self::normalize($value);

        return $plate !== ''
            && preg_match('/^[' . self::RU_LATIN . ']\d{3}[' . self::RU_LATIN . ']{2}\d{2,3}$/', $plate) === 1;
    }

    /** Plate family — used for icons and ANPR filtering. */
    public static function type(?string $value): string
    {
        $plate = self::normalize($value);

        return match (true) {
            $plate === '' => 'empty',
            preg_match('/^[' . self::RU_LATIN . ']\d{3}[' . self::RU_LATIN . ']{2}\d{3}$/', $plate) === 1 => 'ru-12',
            preg_match('/^[' . self::RU_LATIN . ']\d{3}[' . self::RU_LATIN . ']{2}\d{2}$/', $plate) === 1  => 'ru',
            preg_match('/^[A-Z]{2}\d{3}[A-Z]{2}$/', $plate) === 1                                          => 'eu',
            preg_match('/^[A-Z0-9]{4,12}$/', $plate) === 1                                                 => 'other',
            default                                                                                        => 'unknown',
        };
    }

    /** Region code of a Russian plate, or null. */
    public static function region(?string $value): ?string
    {
        $plate = self::normalize($value);
        if (!self::isRussian($plate)) {
            return null;
        }

        return substr($plate, -3) !== '' && strlen($plate) === 9 ? substr($plate, -3) : substr($plate, -2);
    }

    /** Mask for public logs: `А123ВС 77` → `А1••ВС 77`. */
    public static function mask(?string $value): string
    {
        $plate = self::format($value);
        $length = mb_strlen($plate, 'UTF-8');
        if ($length < 6) {
            return $plate;
        }

        // Keep the first two and last four characters, hide the middle digits.
        $head = mb_substr($plate, 0, 2, 'UTF-8');
        $tail = mb_substr($plate, -4, 4, 'UTF-8');

        return $head . str_repeat('•', max(1, $length - 6)) . $tail;
    }

    /**
     * Loose comparison helper: equal when the canonical forms match.
     * Accepts any of the spellings a human or a camera might produce.
     */
    public static function equals(?string $a, ?string $b): bool
    {
        $left = self::normalize($a);

        return $left !== '' && $left === self::normalize($b);
    }
}
