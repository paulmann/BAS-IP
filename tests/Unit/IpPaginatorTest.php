<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Support\Ip;
use BasIp\Support\Paginator;
use BasIp\Tests\Support\TestCase;

final class IpPaginatorTest extends TestCase
{
    public function testIpValidation(): void
    {
        $this->assertTrue(Ip::isValid('192.168.1.1'));
        $this->assertTrue(Ip::isValid('2001:db8::1'));
        $this->assertFalse(Ip::isValid('999.1.1.1'));
        $this->assertFalse(Ip::isValid('not-an-ip'));
        $this->assertFalse(Ip::isValid(''));
    }

    public function testPrivateAndLoopbackDetection(): void
    {
        $this->assertTrue(Ip::isPrivate('192.168.1.10'));
        $this->assertTrue(Ip::isPrivate('10.0.0.5'));
        $this->assertTrue(Ip::isPrivate('127.0.0.1'));
        $this->assertTrue(Ip::isPrivate('::1'));
        $this->assertFalse(Ip::isPrivate('8.8.8.8'));
    }

    public function testCidrMatching(): void
    {
        $this->assertTrue(Ip::inCidr('192.168.1.50', '192.168.1.0/24'));
        $this->assertFalse(Ip::inCidr('192.168.2.50', '192.168.1.0/24'));
        $this->assertTrue(Ip::inCidr('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(Ip::inCidr('not-an-ip', '10.0.0.0/8'));
    }

    public function testAllowlistMixesIpsAndCidrs(): void
    {
        $this->assertTrue(Ip::allowed('203.0.113.10', ['203.0.113.10'], []));
        $this->assertTrue(Ip::allowed('198.51.100.7', [], ['198.51.100.0/24']));
        $this->assertFalse(Ip::allowed('198.51.101.7', [], ['198.51.100.0/24']));
        $this->assertFalse(Ip::allowed('8.8.8.8', ['203.0.113.10'], []));
    }

    public function testAnonymizeForLogs(): void
    {
        $this->assertSame('192.168.1.x', Ip::anonymize('192.168.1.55'));
        $this->assertStringContains('2001:db8', Ip::anonymize('2001:db8:85a3::8a2e:370:7334'));
    }

    public function testIpv6MappedAddressIsUnwrapped(): void
    {
        $this->assertSame('192.168.1.1', Ip::normalize('::ffff:192.168.1.1'));
    }

    /* ─────────────────────────── paginator ─────────────────────────── */

    public function testPaginatorMath(): void
    {
        $items = array_fill(0, 10, ['x' => 1]);
        $p = new Paginator($items, 95, 10, 3);

        $this->assertSame(95, $p->total());
        $this->assertSame(10, $p->lastPage());
        $this->assertSame(3, $p->currentPage());
        $this->assertSame(21, $p->firstItem());
        $this->assertSame(30, $p->lastItem());
        $this->assertTrue($p->hasPages());
        $this->assertTrue($p->hasMore());
        $this->assertTrue($p->hasPrevious());
    }

    public function testPaginatorClampsInvalidInput(): void
    {
        $p = new Paginator([], 0, 0, 999);
        $this->assertSame(1, $p->perPage());
        $this->assertSame(1, $p->lastPage());
        $this->assertSame(1, $p->currentPage());
        $this->assertSame(0, $p->firstItem());
        $this->assertFalse($p->hasPages());
    }

    public function testPaginatorWindow(): void
    {
        $p = new Paginator([], 1000, 10, 50);
        $pages = $p->pages();
        $this->assertContains(50, $pages);
        $this->assertContains(1, $pages);
        $this->assertContains(100, $pages);
        $this->assertContains(null, $pages);   // ellipsis marker
    }
}
