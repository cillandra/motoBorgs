<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_input_is_exact_centavos(): void
    {
        $this->assertSame(10001, Money::parse('100.01'));
        $this->assertSame(10, Money::parse('0.1'));
        $this->assertSame(0, Money::parse('0'));
        $this->assertSame('100.01', Money::format(10001));
    }

    public function test_display_groups_thousands_without_changing_decimal_input_format(): void
    {
        foreach ([0 => '0.00', 99999 => '999.99', 100001 => '1,000.01', 1807800 => '18,078.00', -1807800 => '-18,078.00', 999999999 => '9,999,999.99'] as $centavos => $expected) {
            $this->assertSame($expected, Money::display($centavos));
        }
        $this->assertSame('18078.00', Money::format(1807800));
        $this->assertSame(1807800, Money::parse(Money::format(1807800)));
    }

    public function test_fractional_centavo_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('1.005');
    }

    public function test_negative_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('-0.01');
    }

    public function test_oversized_input_is_rejected_without_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('99999999999999999999999999.00');
    }

    public function test_discount_remainder_uses_stable_line_order(): void
    {
        $this->assertSame([11 => 1, 12 => 0], Money::allocate(1, [11 => 100, 12 => 100]));
        $this->assertSame([11 => 33, 12 => 67], Money::allocate(100, [11 => 100, 12 => 200]));
    }

    public function test_over_discount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::allocate(201, [11 => 100, 12 => 100]);
    }
}
