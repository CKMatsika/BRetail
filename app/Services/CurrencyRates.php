<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CurrencyRates
{
    private function lock(User $u, string $permission): void
    {
        Tenant::whereKey(app(TenantContext::class)->requireId())->lockForUpdate()->firstOrFail();
        $u->refresh();
        $u->unsetRelation('role');
        InventoryAccess::permit($u, $permission);
        abort_unless($u->active && $u->role->company_wide && TenantModule::where('key', 'currencies')->where('enabled', true)->exists(), 403);
    }

    public function currency(User $u, array $input): Currency
    {
        $d = Validator::make($input, ['code' => 'required|regex:/^[A-Z]{3}$/', 'name' => 'required|string|max:80', 'minor_units' => 'required|integer|min:0|max:4'])->validate();

        return DB::transaction(function () use ($u, $d) {
            $this->lock($u, 'currencies.configure');
            if (Currency::where('code', $d['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Currency already exists; its precision cannot be changed.']);
            }$c = Currency::create($d);
            Audit::record('currency.created', (string) $c->id);

            return $c;
        });
    }

    public function propose(User $u, array $input): ExchangeRate
    {
        $d = Validator::make($input, ['currency_id' => 'required|integer', 'effective_on' => 'required|date_format:Y-m-d', 'rate' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,12})?$/'], 'source' => 'required|string|max:255'])->validate();

        return DB::transaction(function () use ($u, $d) {
            $this->lock($u, 'currencies.rates');
            $c = Currency::findOrFail($d['currency_id']);
            if (! $c->active || ! BigDecimal::of($d['rate'])->isPositive() || $c->code === Company::firstOrFail()->base_currency) {
                throw ValidationException::withMessages(['rate' => 'Choose an active foreign currency and a positive rate.']);
            }if (ExchangeRate::where('currency_id', $c->id)->where('effective_on', $d['effective_on'])->exists()) {
                throw ValidationException::withMessages(['effective_on' => 'A rate already exists for this date.']);
            }$r = ExchangeRate::create([...$d, 'created_by' => $u->id]);
            Audit::record('currency.rate_proposed', (string) $r->id);

            return $r;
        });
    }

    public function approve(User $u, int $id): ExchangeRate
    {
        return DB::transaction(function () use ($u, $id) {
            $this->lock($u, 'currencies.approve');
            $r = ExchangeRate::findOrFail($id);
            if (! $r->approved_at) {
                $r->update(['approved_by' => $u->id, 'approved_at' => now()]);
                Audit::record('currency.rate_approved', (string) $r->id);
            }

return $r;
        });
    }

    public function snapshot(string $code, string $date, string $amount): array
    {
        Validator::make(['date' => $date, 'amount' => $amount], ['date' => 'required|date_format:Y-m-d', 'amount' => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,4})?$/']])->validate();
        $c = Currency::where('code', $code)->where('active', true)->firstOrFail();
        $original = BigDecimal::of($amount);
        try {
            $original = $original->toScale($c->minor_units);
        } catch (RoundingNecessaryException) {
            throw ValidationException::withMessages(['amount' => 'Amount exceeds currency precision.']);
        }
        $base = Company::firstOrFail()->base_currency;
        $r = $code === $base ? null : ExchangeRate::where('currency_id', $c->id)->whereNotNull('approved_at')->where('effective_on', '<=', $date)->orderByDesc('effective_on')->first();
        if ($code !== $base && ! $r) {
            throw ValidationException::withMessages(['rate' => 'No approved exchange rate applies to this date.']);
        }
        $rate = $r?->rate ?? '1.000000000000';
        $exact = $original->multipliedBy($rate);
        $rounded = $exact->toScale(4, RoundingMode::HalfUp);

        return ['currency' => $code, 'base_currency' => $base, 'original_amount' => (string) $original, 'exchange_rate' => $rate, 'exchange_rate_id' => $r?->id, 'base_amount' => (string) $rounded, 'rounding_difference' => (string) $rounded->minus($exact), 'effective_on' => $r?->effective_on ?? $date];
    }

    /** Return the USD value of one native currency unit using the tenant's approved rate for the supplied date. */
    public function usdUnitRate(string $code, string $date): array
    {
        Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $currency = Currency::where('code', $code)->where('active', true)->firstOrFail();
        $base = Company::firstOrFail()->base_currency;
        if ($code === 'USD') {
            return ['currency' => 'USD', 'usd_per_unit' => '1.000000000000', 'exchange_rate_id' => null, 'effective_on' => $date, 'rate_convention' => 'USD per native currency unit'];
        }
        if ($code !== 'ZWG' || ! in_array($base, ['USD', 'ZWG'], true)) {
            throw ValidationException::withMessages(['currency' => 'Zimbabwe mixed-currency payroll supports USD and ZWG only.']);
        }

        if ($base === 'USD') {
            $rate = ExchangeRate::where('currency_id', $currency->id)->whereNotNull('approved_at')->where('effective_on', '<=', $date)->orderByDesc('effective_on')->first();
            if (! $rate) throw ValidationException::withMessages(['rate' => 'No approved ZWG-to-USD exchange rate applies on the actual payroll payment date.']);
            return ['currency' => 'ZWG', 'usd_per_unit' => (string) $rate->rate, 'exchange_rate_id' => $rate->id, 'effective_on' => $rate->effective_on, 'rate_convention' => 'USD per ZWG'];
        }

        $usd = Currency::where('code', 'USD')->where('active', true)->firstOrFail();
        $rate = ExchangeRate::where('currency_id', $usd->id)->whereNotNull('approved_at')->where('effective_on', '<=', $date)->orderByDesc('effective_on')->first();
        if (! $rate || ! BigDecimal::of((string) $rate->rate)->isPositive()) {
            throw ValidationException::withMessages(['rate' => 'No approved USD-to-ZWG exchange rate applies on the actual payroll payment date.']);
        }

        return ['currency' => 'ZWG', 'usd_per_unit' => (string) BigDecimal::of('1')->dividedBy((string) $rate->rate, 12, RoundingMode::HalfUp), 'exchange_rate_id' => $rate->id, 'effective_on' => $rate->effective_on, 'rate_convention' => 'USD per ZWG (inverse of approved ZWG-base rate)'];
    }
}
