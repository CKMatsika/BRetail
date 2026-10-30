<?php

namespace Tests\Feature;

use App\Models\ForeignEntry;
use App\Models\TenantModule;
use App\Models\Warehouse;
use App\Services\AccountingLedger;
use App\Services\AccountingSetup;
use App\Services\CurrencyRates;
use App\Services\ForeignLedger;
use App\Services\Onboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForeignLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_balance_revaluation_reversal_and_realized_difference(): void
    {
        $u = app(Onboarding::class)->register(['name' => 'Owner', 'email' => 'foreign@example.test', 'password' => 'SecurePassword123', 'company_name' => 'FX', 'industry' => 'general', 'base_currency' => 'USD', 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'warehouse_name' => 'Main']);
        TenantModule::whereIn('key', ['inventory', 'accounting', 'currencies'])->update(['enabled' => true]);
        $today = AccountingLedger::today();
        $year = substr($today, 0, 4);
        app(AccountingSetup::class)->initialize($u, ['name' => 'FY', 'starts_on' => $year.'-01-01', 'ends_on' => $year.'-12-31']);
        $r = app(CurrencyRates::class);
        $c = $r->currency($u, ['code' => 'ZAR', 'name' => 'Rand', 'minor_units' => 2]);
        foreach (['01-01' => '0.05', '02-01' => '0.06', '03-01' => '0.07'] as $date => $rate) {
            $x = $r->propose($u, ['currency_id' => $c->id, 'effective_on' => $year.'-'.$date, 'rate' => $rate, 'source' => 'Test']);
            $r->approve($u, $x->id);
        }
        $f = app(ForeignLedger::class);
        $l = app(AccountingLedger::class);
        $a = $f->account($u, ['branch_id' => Warehouse::first()->branch_id, 'currency_id' => $c->id, 'name' => 'Rand safe', 'kind' => 'safe']);
        $d = ['request_id' => (string) Str::uuid(), 'date' => $year.'-01-01', 'reference' => 'OPEN', 'direction' => 'increase', 'amount' => '100', 'offset_account_id' => $l->account('opening_equity')->id, 'cashflow' => 'financing'];
        $e = $f->post($u, $a->id, $d);
        $this->assertSame($e->id, $f->post($u, $a->id, $d)->id);
        $this->assertSame(['original' => '100.0000', 'base' => '5.0000'], $f->balances($a, 'training'));
        $v = $f->revalue($u, $a->id, ['request_id' => (string) Str::uuid(), 'date' => $year.'-02-01', 'reference' => 'VALUE']);
        $this->assertSame('6.0000', $f->balances($a, 'training')['base']);
        $f->reverseRevaluation($u, $v->id, ['request_id' => (string) Str::uuid(), 'date' => $year.'-02-02', 'reference' => 'REVERSE']);
        $this->assertSame('5.0000', $f->balances($a, 'training')['base']);
        $f->post($u, $a->id, ['request_id' => (string) Str::uuid(), 'date' => $year.'-03-01', 'reference' => 'SPEND', 'direction' => 'decrease', 'amount' => '40', 'offset_account_id' => $l->account('cash_safe')->id, 'cashflow' => 'operating']);
        $this->assertSame(['original' => '60.0000', 'base' => '3.0000'], $f->balances($a, 'training'));
        $this->assertSame('3.0000', $l->balance($a->account_id, 'training'));
        $this->assertSame('-0.8000', $l->balance($f->mapping('fx_gain'),'training'));
        $this->assertSame(4,ForeignEntry::count());
    }
}
