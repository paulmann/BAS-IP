<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Support\Arr;
use BasIp\Support\Str;
use BasIp\Tests\Support\TestCase;

final class StrArrTest extends TestCase
{
    public function testSlugHandlesCyrillic(): void
    {
        $this->assertSame('zhk-romashka', Str::slug('ЖК «Ромашка»'));
        $this->assertSame('ivanov-ivan', Str::slug('  Иванов   Иван  '));
        $this->assertSame('a-b-c', Str::slug('a/b\\c'));
        $this->assertSame('', Str::slug('!!!'));
    }

    public function testTransliterate(): void
    {
        $this->assertSame('Ivanov', Str::transliterate('Иванов'));
        $this->assertSame('schuka', Str::transliterate('щука'));
        $this->assertSame('abc', Str::transliterate('abc'));
    }

    public function testInitials(): void
    {
        $this->assertSame('Иванов И.И.', Str::initials('Иванов Иван Иванович'));
        $this->assertSame('Иванов И.', Str::initials('Иванов Иван'));
        $this->assertSame('Иванов', Str::initials('Иванов'));
        $this->assertSame('', Str::initials(''));
    }

    public function testAvatarInitials(): void
    {
        $this->assertSame('ИИ', Str::avatarInitials('Иванов Иван Иванович'));
        $this->assertSame('И', Str::avatarInitials('Иванов'));
    }

    public function testLimitAndSquash(): void
    {
        $this->assertSame('абв…', Str::limit('абвгде', 3));
        $this->assertSame('абв', Str::limit('абв', 5));
        $this->assertSame('a b c', Str::squash("  a   b\t\nc  "));
    }

    public function testPluralRussianForms(): void
    {
        $this->assertSame('житель', Str::plural(1, 'житель', 'жителя', 'жителей'));
        $this->assertSame('жителя', Str::plural(2, 'житель', 'жителя', 'жителей'));
        $this->assertSame('жителей', Str::plural(5, 'житель', 'жителя', 'жителей'));
        $this->assertSame('жителей', Str::plural(11, 'житель', 'жителя', 'жителей'));
        // 21 → «житель» (not «жителя»): the teen rule only covers 11–19.
        $this->assertSame('житель', Str::plural(21, 'житель', 'жителя', 'жителей'));
        $this->assertSame('жителя', Str::plural(22, 'житель', 'жителя', 'жителей'));
    }

    public function testMaskAndBytes(): void
    {
        $this->assertSame('ad***@example.com', Str::maskEmail('admin@example.com'));
        $this->assertSame('1 KB', Str::bytes(1024));
        $this->assertSame('1.5 MB', Str::bytes(1572864));
        $this->assertSame('0 B', Str::bytes(0));
    }

    public function testStringifyAndToBoolSafe(): void
    {
        $this->assertSame('1', Str::stringify(true));
        $this->assertSame('', Str::stringify(null));
        $this->assertSame('{"a":1}', Str::stringify(['a' => 1]));
        $this->assertTrue(Str::toBoolSafe('да'));
        $this->assertTrue(Str::toBoolSafe('1'));
        $this->assertFalse(Str::toBoolSafe('нет'));
        $this->assertFalse(Str::toBoolSafe('0'));
    }

    public function testArrDotAccess(): void
    {
        $data = ['a' => ['b' => ['c' => 42]], 'list' => [1, 2, 3]];
        $this->assertSame(42, Arr::get($data, 'a.b.c'));
        $this->assertSame(2, Arr::get($data, 'list.1'));
        $this->assertNull(Arr::get($data, 'a.x.y'));
        $this->assertSame('def', Arr::get($data, 'missing', 'def'));
        $this->assertTrue(Arr::has($data, 'a.b.c'));
        $this->assertFalse(Arr::has($data, 'a.b.z'));
    }

    public function testArrSetAndFlatten(): void
    {
        $data = [];
        Arr::set($data, 'x.y.z', 1);
        $this->assertSame(['x' => ['y' => ['z' => 1]]], $data);

        $flat = Arr::flatten(['a' => 1, 'b' => ['c' => 2]]);
        $this->assertSame(['a' => 1, 'b.c' => 2], $flat);
    }

    public function testArrRedactRemovesSecrets(): void
    {
        $redacted = Arr::redact(['login' => 'admin', 'password' => 'hunter2', 'nested' => ['token' => 'abc']]);
        $this->assertSame('admin', $redacted['login']);
        $this->assertSame('••••••', $redacted['password']);
        $this->assertSame('••••••', $redacted['nested']['token']);
    }

    public function testArrOnlyExceptMergeDeep(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => ['d' => 3, 'e' => 4]];
        $this->assertSame(['a' => 1], Arr::only($data, ['a']));
        $this->assertSame(['a' => 1, 'b' => 2], Arr::except($data, ['c']));
        $merged = Arr::mergeDeep(['x' => ['y' => 1, 'z' => 2]], ['x' => ['z' => 9]]);
        $this->assertSame(['x' => ['y' => 1, 'z' => 9]], $merged);
    }
}
