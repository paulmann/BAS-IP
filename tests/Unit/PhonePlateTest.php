<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Support\Phone;
use BasIp\Support\Plate;
use BasIp\Tests\Support\TestCase;

final class PhonePlateTest extends TestCase
{
    /* ─────────────────────────── phones ─────────────────────────── */

    public function testPhoneNormalizationToE164(): void
    {
        $this->assertSame('+79161234567', Phone::normalize('8 (916) 123-45-67'));
        $this->assertSame('+79161234567', Phone::normalize('+7 916 123 45 67'));
        $this->assertSame('+79161234567', Phone::normalize('89161234567'));
        $this->assertSame('+375291234567', Phone::normalize('+375 29 123-45-67'));
        $this->assertSame('', Phone::normalize(''));
        $this->assertSame('', Phone::normalize(null));
    }

    public function testPhoneValidation(): void
    {
        $this->assertTrue(Phone::isValid('+79161234567'));
        $this->assertTrue(Phone::isValid('89161234567'));
        $this->assertFalse(Phone::isValid('123'));
        $this->assertFalse(Phone::isValid('abc'));
        $this->assertFalse(Phone::isValid(''));
    }

    public function testPhoneFormatting(): void
    {
        $this->assertSame('+7 (916) 123-45-67', Phone::format('+79161234567'));
        $this->assertSame('+375 (29) 123-45-67', Phone::format('+375291234567'));
        $this->assertSame('+12345', Phone::format('12345'));
        $this->assertSame('', Phone::format(''));
    }

    public function testTelLinkFormat(): void
    {
        $this->assertSame('+79161234567', Phone::tel('8 (916) 123-45-67'));
        $this->assertSame('', Phone::tel(null));
    }

    public function testRegionDetection(): void
    {
        $this->assertSame('RU/KZ', Phone::region('+79161234567'));
        $this->assertSame('BY', Phone::region('+375291234567'));
        $this->assertSame('UA', Phone::region('+380501234567'));
    }

    /* ─────────────────────────── plates ─────────────────────────── */

    public function testPlateNormalizationMatchesAnprOutput(): void
    {
        // The 12 RU plate letters map to their identical Latin glyphs — NOT to
        // a phonetic transliteration (В→B, not В→V), otherwise ANPR never matches.
        $this->assertSame('A123BC77', Plate::normalize('А123ВС77'));
        $this->assertSame('A123BC77', Plate::normalize('а 123 вс 77'));
        $this->assertSame('A123BC77', Plate::normalize('A-123-BC-77'));
        $this->assertSame('A123BC777', Plate::normalize('А123ВС777'));
        $this->assertSame('', Plate::normalize(null));
    }

    public function testPlateEqualityAcrossSpellings(): void
    {
        $this->assertTrue(Plate::equals('А123ВС77', 'a 123 bc 77'));
        $this->assertTrue(Plate::equals('А123ВС77', 'A123BC77'));
        $this->assertFalse(Plate::equals('А123ВС77', 'А123ВС78'));
        $this->assertFalse(Plate::equals('', ''));
    }

    public function testPlateValidation(): void
    {
        $this->assertTrue(Plate::isValid('А123ВС77'));
        $this->assertTrue(Plate::isValid('A123BC777'));
        $this->assertFalse(Plate::isValid('XYZ'));       // no digits
        $this->assertFalse(Plate::isValid(''));
        $this->assertFalse(Plate::isValid(null));
    }

    public function testPlateFormatIsHumanReadable(): void
    {
        $this->assertSame('А123ВС 77', Plate::format('a123bc77'));
        $this->assertSame('А123ВС 777', Plate::format('A123BC777'));
        $this->assertSame('', Plate::format(''));
    }

    public function testPlateTypeAndRegion(): void
    {
        $this->assertSame('ru', Plate::type('А123ВС77'));
        $this->assertSame('ru-12', Plate::type('А123ВС777'));
        $this->assertSame('empty', Plate::type(''));
        $this->assertSame('77', Plate::region('А123ВС77'));
        $this->assertNull(Plate::region('not-a-plate'));
    }

    public function testPlateMaskHidesTheMiddle(): void
    {
        $masked = Plate::mask('А123ВС77');
        $this->assertStringContains('•', $masked);
        $this->assertStringNotContains('123', $masked);
    }
}
