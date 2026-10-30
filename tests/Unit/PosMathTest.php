<?php

namespace Tests\Unit;

use App\Services\PosMath;
use PHPUnit\Framework\TestCase;

class PosMathTest extends TestCase
{
    public function test_decimal_tax_and_discount_are_exact(): void
    {
        $line = PosMath::line('3.000', '0.1000', '10.00', '15.0000');
        $this->assertSame(['subtotal' => '0.30', 'discount' => '0.03', 'net' => '0.27', 'tax' => '0.04', 'total' => '0.31'], $line);
    }

    public function test_cumulative_return_allocation_preserves_rounding_residue(): void
    {
        $parts = [];
        foreach (['0', '1', '2'] as $prior) {
            $parts[] = PosMath::portion('1.00', '3.000', $prior, '1.000');
        }$this->assertSame(['0.33', '0.34', '0.33'], $parts);
        $this->assertSame('1.00', PosMath::sum($parts));
        $this->assertSame('0.0001', PosMath::portion('0.0001', '0.003', '0.001', '0.001', 4));
    }
}
