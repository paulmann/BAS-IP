<?php

declare(strict_types=1);

namespace BasIp\Tests\Support;

use RuntimeException;
use Throwable;

/**
 * Minimal assertion base class.
 *
 * The project ships **zero runtime dependencies**, and the test-suite follows
 * the same rule: no PHPUnit required. `php tests/run.php` executes everything.
 * A `phpunit.xml.dist` is provided for teams that prefer PHPUnit — the tests are
 * written so both runners work (plain methods named `test*`).
 */
abstract class TestCase
{
    public int $assertions = 0;

    /** @var array<int, string> */
    public array $failures = [];

    /** @return array<int, string> */
    public function runTests(): array
    {
        $methods = array_filter(
            get_class_methods($this),
            static fn (string $m): bool => str_starts_with($m, 'test')
        );
        sort($methods);

        $ran = [];
        foreach ($methods as $method) {
            try {
                $this->setUp();
                $this->{$method}();
                $ran[] = $method;
            } catch (Throwable $e) {
                // Record, do not re-throw: `fail()` itself throws, and an
                // exception escaping here would abort the whole suite.
                $this->failures[] = sprintf(
                    '%s::%s → %s (%s:%d)',
                    substr(static::class, (int) strrpos(static::class, '\\') + 1),
                    $method,
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine()
                );
            } finally {
                try {
                    $this->tearDown();
                } catch (Throwable) {
                    // teardown must not mask a test failure
                }
            }
        }

        return $ran;
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    /* ─────────────────────────── assertions ─────────────────────────── */

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== true) {
            $this->fail($message ?: 'Expected true, got ' . $this->describe($value));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== false) {
            $this->fail($message ?: 'Expected false, got ' . $this->describe($value));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail($message ?: sprintf('Expected %s, got %s', $this->describe($expected), $this->describe($actual)));
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            $this->fail($message ?: sprintf('Expected a value other than %s', $this->describe($expected)));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected != $actual) {
            $this->fail($message ?: sprintf('Expected %s, got %s', $this->describe($expected), $this->describe($actual)));
        }
    }

    /** Float comparison with an epsilon — money maths must not flap. */
    protected function assertFloat(float $expected, float $actual, float $epsilon = 0.005, string $message = ''): void
    {
        $this->assertions++;
        if (abs($expected - $actual) > $epsilon) {
            $this->fail($message ?: sprintf('Expected %s ± %s, got %s', $expected, $epsilon, $actual));
        }
    }

    protected function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if (!$value instanceof $class) {
            $this->fail($message ?: sprintf('Expected an instance of %s, got %s', $class, get_debug_type($value)));
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== null) {
            $this->fail($message ?: 'Expected null, got ' . $this->describe($value));
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value === null) {
            $this->fail($message ?: 'Expected a value, got null');
        }
    }

    protected function assertCount(int $expected, mixed $countable, string $message = ''): void
    {
        $this->assertions++;
        $actual = is_countable($countable) ? count($countable) : -1;
        if ($actual !== $expected) {
            $this->fail($message ?: sprintf('Expected %d items, got %d', $expected, $actual));
        }
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!in_array($needle, $haystack, true)) {
            $this->fail($message ?: sprintf('Expected array to contain %s', $this->describe($needle)));
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            $this->fail($message ?: sprintf('Expected string to contain "%s"', $needle));
        }
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            $this->fail($message ?: sprintf('Expected string NOT to contain "%s"', $needle));
        }
    }

    protected function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        $this->assertions++;
        if (preg_match($pattern, $subject) !== 1) {
            $this->fail($message ?: sprintf('Expected "%s" to match %s', $subject, $pattern));
        }
    }

    protected function assertGreaterThan(float|int $threshold, float|int $value, string $message = ''): void
    {
        $this->assertions++;
        if (!($value > $threshold)) {
            $this->fail($message ?: sprintf('Expected %s > %s', $value, $threshold));
        }
    }

    protected function assertLessThan(float|int $threshold, float|int $value, string $message = ''): void
    {
        $this->assertions++;
        if (!($value < $threshold)) {
            $this->fail($message ?: sprintf('Expected %s < %s', $value, $threshold));
        }
    }

    /** Assert that the callback throws the given exception class. */
    protected function assertThrows(string $class, callable $callback, string $message = ''): void
    {
        $this->assertions++;
        try {
            $callback();
        } catch (Throwable $e) {
            if (!($e instanceof $class)) {
                $this->fail($message ?: sprintf('Expected %s, got %s: %s', $class, $e::class, $e->getMessage()));
            }

            return;
        }
        $this->fail($message ?: 'Expected ' . $class . ' to be thrown, nothing was');
    }

    protected function fail(string $message): never
    {
        $this->failures[] = $message;
        throw new RuntimeException($message);
    }

    private function describe(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ') ' . json_encode(array_slice($value, 0, 6), JSON_UNESCAPED_UNICODE);
        }
        if (is_object($value)) {
            return $value::class;
        }

        return var_export($value, true);
    }
}
