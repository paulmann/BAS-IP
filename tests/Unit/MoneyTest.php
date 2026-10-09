<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Support\Money;
use BasIp\Tests\Support\TestCase;

/**
 * Money is the one place where a rounding bug becomes a real argument with a
 * resident, so the split logic is tested exhaustively.
 */
final class MoneyTest extends TestCase
{
    public function testParseAndToFloatRoundTrip(): void
    {
        $this->assertSame(1500000, Money::parse(15000.00));
        $this->assertSame(214286, Money::parse('2142,86'));
        $this->assertSame(1, Money::parse(0.01));
        $this->assertSame(0, Money::parse(''));
        $this->assertFloat(15000.00, Money::toFloat(1500000));
        $this->assertFloat(-50.25, Money::toFloat(-5025));
    }

    public function testSplitDistributesRemainderWithoutLosingKopecks(): void
    {
        // 15000.00 / 7 = 2142.857142…
        $parts = Money::split(1500000, 7);
        $this->assertCount(7, $parts);
        $this->assertSame(1500000, array_sum($parts));
        $this->assertContains(214286, $parts);
        $this->assertContains(214285, $parts);
    }

    public function testSplitNeverExceedsTheTotal(): void
    {
        foreach ([1, 2, 3, 7, 11, 13, 97, 250] as $count) {
            $parts = Money::split(1000001, $count);
            $this->assertSame(1000001, array_sum($parts), 'total mismatch for ' . $count . ' parts');
            $this->assertCount($count, $parts);
        }
    }

    public function testSplitHandlesZeroAndNegative(): void
    {
        // A zero total still yields one zero per participant — predictable shape.
        $this->assertSame([0, 0, 0, 0, 0], Money::split(0, 5));
        $this->assertSame([], Money::split(1000, 0));
        $parts = Money::split(-9000, 3);
        $this->assertSame(-9000, array_sum($parts));
    }

    public function testWeightedSplit(): void
    {
        // 1000.00 across areas 30/50/20 → 300/500/200
        $parts = Money::splitWeighted(100000, [30.0, 50.0, 20.0]);
        $this->assertSame([30000, 50000, 20000], $parts);

        // Weights that do not divide cleanly still sum to the total.
        $parts = Money::splitWeighted(100000, [1.0, 1.0, 1.0]);
        $this->assertSame(100000, array_sum($parts));

        $parts = Money::splitWeighted(100000, [0.0, 0.0, 0.0]);
        $this->assertSame(100000, array_sum($parts), 'zero weights must fall back to an even split');
    }

    public function testFormatting(): void
    {
        // Thousands separator and the gap before the symbol are U+00A0 (nbsp)
        // so the amount never wraps across lines in a table cell.
        $nbsp = "\u{00A0}";
        $this->assertSame('15' . $nbsp . '000' . $nbsp . '₽', Money::format(15000));
        $this->assertSame('2' . $nbsp . '142,86' . $nbsp . '₽', Money::format(2142.86));
        $this->assertSame('0' . $nbsp . '₽', Money::format(0));
        $this->assertSame('-50,25' . $nbsp . '₽', Money::format(-50.25));
        $this->assertSame('1' . $nbsp . '234,50' . $nbsp . '$', Money::format(1234.5, '$'));
        $this->assertSame('10' . $nbsp . '000', Money::format(10000, '', 0));
    }

    public function testRoundingModes(): void
    {
        $this->assertFloat(2.5, Money::round(2.4567, 'half_up', 1));
        $this->assertFloat(100.0, Money::round(99.999, 'half_up', 0));
        $this->assertFloat(2.46, Money::round(2.4567));
    }
}
