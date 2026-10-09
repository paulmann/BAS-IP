<?php

declare(strict_types=1);

namespace BasIp\Tests\Unit;

use BasIp\Support\Validator;
use BasIp\Tests\Support\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndMax(): void
    {
        $v = Validator::make(['name' => ''], ['name' => 'required|max:10'], ['name' => 'Имя']);
        $this->assertTrue($v->fails());
        $this->assertStringContains('Имя обязательно', (string) $v->firstError());

        $v = Validator::make(['name' => str_repeat('а', 11)], ['name' => 'required|max:10']);
        $this->assertTrue($v->fails());

        $v = Validator::make(['name' => 'Иван'], ['name' => 'required|max:10']);
        $this->assertTrue($v->passes());
        $this->assertSame(['name' => 'Иван'], $v->validated());
    }

    public function testNullableSkipsMissingValues(): void
    {
        $v = Validator::make(['email' => ''], ['email' => 'nullable|email']);
        $this->assertTrue($v->passes());
    }

    public function testEmailRule(): void
    {
        $this->assertTrue(Validator::make(['e' => 'user@example.com'], ['e' => 'email'])->validate());
        $this->assertFalse(Validator::make(['e' => 'not-an-email'], ['e' => 'email'])->validate());
    }

    public function testNumericAndBetween(): void
    {
        $v = Validator::make(['amount' => '1500.50'], ['amount' => 'required|numeric|between:0.01,1000000']);
        $this->assertTrue($v->passes());
        $this->assertFloat(1500.5, (float) $v->validated()['amount']);

        $this->assertFalse(Validator::make(['amount' => '-5'], ['amount' => 'numeric|between:0.01,100'])->validate());
        $this->assertFalse(Validator::make(['amount' => 'abc'], ['amount' => 'numeric'])->validate());
    }

    public function testPhoneRuleNormalizes(): void
    {
        $v = Validator::make(['phone' => '8 (916) 123-45-67'], ['phone' => 'phone']);
        $this->assertTrue($v->passes());
        $this->assertSame('+79161234567', $v->validated()['phone']);

        $this->assertFalse(Validator::make(['phone' => '12'], ['phone' => 'phone'])->validate());
    }

    public function testPlateRuleNormalizes(): void
    {
        $v = Validator::make(['plate' => 'а 123 вс 77'], ['plate' => 'plate']);
        $this->assertTrue($v->passes());
        $this->assertSame('A123BC77', $v->validated()['plate']);
    }

    public function testInRule(): void
    {
        $this->assertTrue(Validator::make(['role' => 'admin'], ['role' => 'in:admin,operator,resident'])->validate());
        $this->assertFalse(Validator::make(['role' => 'root'], ['role' => 'in:admin,operator'])->validate());
    }

    public function testTimeAndDateRules(): void
    {
        $this->assertTrue(Validator::make(['t' => '23:30'], ['t' => 'time'])->validate());
        $this->assertFalse(Validator::make(['t' => '25:00'], ['t' => 'time'])->validate());
        $this->assertTrue(Validator::make(['d' => '2026-10-09'], ['d' => 'date'])->validate());
        $this->assertFalse(Validator::make(['d' => 'not a date'], ['d' => 'date'])->validate());
    }

    public function testBooleanAndIntegerCast(): void
    {
        $v = Validator::make(['flag' => 'on', 'n' => '42'], ['flag' => 'boolean', 'n' => 'integer']);
        $this->assertTrue($v->passes());
        $this->assertSame(1, $v->validated()['flag']);
        $this->assertSame(42, $v->validated()['n']);
    }

    public function testWildcardRules(): void
    {
        $data = [
            'contacts' => [
                ['type' => 'phone', 'value' => '+79161234567'],
                ['type' => 'phone', 'value' => '12'],
            ],
        ];
        $v = Validator::make($data, ['contacts.*.value' => 'required|phone']);
        $this->assertTrue($v->fails());
        $this->assertStringContains('contacts.1.value', implode(' ', array_keys($v->errors())));
    }

    public function testCustomMessagesAndLabels(): void
    {
        $v = Validator::make(
            ['apt' => ''],
            ['apt' => 'required'],
            ['apt' => 'Номер квартиры'],
            ['apt.required' => 'Укажите квартиру — без неё нельзя выставить счёт.']
        );
        $this->assertTrue($v->fails());
        $this->assertSame('Укажите квартиру — без неё нельзя выставить счёт.', $v->firstError());
    }

    public function testConfirmedRule(): void
    {
        $this->assertTrue(Validator::make(
            ['p' => 'secret123', 'p_confirmation' => 'secret123'],
            ['p' => 'confirmed']
        )->validate());
        $this->assertFalse(Validator::make(
            ['p' => 'secret123', 'p_confirmation' => 'other'],
            ['p' => 'confirmed']
        )->validate());
    }
}
