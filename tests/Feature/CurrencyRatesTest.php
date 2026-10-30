<?php

namespace Tests\Feature;

use App\Models\TenantModule;
use App\Services\CurrencyRates;
use App\Services\Onboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CurrencyRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_effective_rates_convert_with_exact_precision(): void
    {
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'fx@example.test', 'password' => 'SecurePassword123', 'company_name' => 'FX shop', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::where('key', 'currencies')->update(['enabled' => true]);
        $s = app(CurrencyRates::class);
        $s->currency($u, ['code' => 'USD', 'name' => 'US Dollar', 'minor_units' => 2]);
        $c = $s->currency($u, ['code' => 'ZAR', 'name' => 'Rand', 'minor_units' => 2]);
        $r = $s->propose($u, ['currency_id' => $c->id, 'effective_on' => '2026-01-01', 'rate' => '0.055555555555', 'source' => 'Test rate']);
        try {
            $s->snapshot('ZAR', '2026-01-02', '100');
            $this->fail('Unapproved rate used');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $s->approve($u, $r->id);
        $v = $s->snapshot('ZAR', '2026-01-02', '100');
        $this->assertSame('5.5556', $v['base_amount']);
        $this->assertSame('100.00', $v['original_amount']);
        $this->assertSame($r->id, $v['exchange_rate_id']);
        $next = $s->propose($u, ['currency_id' => $c->id, 'effective_on' => '2026-02-01', 'rate' => '0.06', 'source' => 'Next rate']);
        $s->approve($u, $next->id);
        $this->assertSame('5.5556', $s->snapshot('ZAR', '2026-01-31', '100')['base_amount']);
        $this->assertSame('6.0000', $s->snapshot('ZAR', '2026-02-01', '100')['base_amount']);
        $this->assertSame('10.0000', $s->snapshot('USD', '2026-01-01', '10')['base_amount']);
        try {
            $s->snapshot('ZAR', '2025-12-31', '1');
            $this->fail('Missing historical rate accepted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        try {
            $s->snapshot('ZAR', '2026-01-01', '1.001');
            $this->fail('Invalid precision accepted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->expectException(\LogicException::class);
        $r->refresh()->update(['rate' => '1']);
    }
}
