<?php

declare(strict_types=1);

namespace BasIp\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Time source. Injectable so the test-suite can freeze time and so every
 * timestamp in the app shares one timezone.
 */
final class Clock
{
    private static ?Clock $instance = null;

    private ?int $frozen = null;

    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    public static function instance(): Clock
    {
        return self::$instance ??= new Clock(new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
    }

    public static function setInstance(?Clock $clock): void
    {
        self::$instance = $clock;
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function now(): int
    {
        return $this->frozen ?? time();
    }

    public function datetime(?int $timestamp = null): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . ($timestamp ?? $this->now())))->setTimezone($this->timezone);
    }

    public function format(string $format = 'Y-m-d H:i:s', ?int $timestamp = null): string
    {
        return $this->datetime($timestamp)->format($format);
    }

    public function iso(?int $timestamp = null): string
    {
        return $this->datetime($timestamp)->format(DateTimeInterface::ATOM);
    }

    /** Freeze time for tests. */
    public function freeze(int $timestamp): void
    {
        $this->frozen = $timestamp;
    }

    public function unfreeze(): void
    {
        $this->frozen = null;
    }

    public function travel(int $seconds): void
    {
        $this->frozen = $this->now() + $seconds;
    }

    public function isFrozen(): bool
    {
        return $this->frozen !== null;
    }
}
