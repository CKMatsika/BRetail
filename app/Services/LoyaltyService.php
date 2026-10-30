<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\Company;
use App\Models\LoyaltyEntry;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyPromotion;
use App\Models\PosCustomer;
use App\Models\PosPayment;
use App\Models\PosRefundPayment;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoyaltyService
{
    public function program(): LoyaltyProgram
    {
        $company = Company::firstOrFail();

        return LoyaltyProgram::firstOrCreate([], [
            'active' => true,
            'earn_points_per_currency' => '1.000',
            'value_per_point' => '0.0100',
            'max_redemption_percent' => '50.00',
            'minimum_redemption_points' => '100.000',
            'expiry_days' => 365,
            'consent_version' => config('loyalty.consent_version'),
        ]);
    }

    public function today(): string
    {
        return CarbonImmutable::now(config('pos.report_timezone'))->toDateString();
    }

    public function enrolled(PosCustomer $customer): bool
    {
        return $customer->loyalty_consent_at !== null && $customer->loyalty_opted_out_at === null;
    }

    public function enroll(PosCustomer $customer, User $actor): PosCustomer
    {
        $this->program();
        $customer->forceFill([
            'loyalty_number' => $customer->loyalty_number ?: $this->number(),
            'loyalty_consent_at' => now(),
            'loyalty_consent_version' => $this->program()->consent_version,
            'loyalty_opted_out_at' => null,
        ])->save();
        Audit::record('loyalty.customer_enrolled', (string) $customer->id, ['actor_id' => $actor->id, 'consent_version' => $customer->loyalty_consent_version]);

        return $customer;
    }

    public function withdraw(PosCustomer $customer, User $actor): void
    {
        DB::transaction(function () use ($customer, $actor) {
            $customer = PosCustomer::lockForUpdate()->findOrFail($customer->id);
            $customer->forceFill(['loyalty_consent_at' => null, 'loyalty_opted_out_at' => now()])->save();
            Audit::record('loyalty.customer_opted_out', (string) $customer->id, ['actor_id' => $actor->id]);
        });
    }

    private function number(): string
    {
        do {
            $number = 'BR-'.Str::upper(Str::random(12));
        } while (PosCustomer::where('loyalty_number', $number)->exists());

        return $number;
    }

    private function entries(int $customerId)
    {
        return LoyaltyEntry::where('customer_id', $customerId)->orderBy('id')->get();
    }

    private function lots($entries): array
    {
        $lots = [];
        foreach ($entries as $entry) {
            if ($entry->kind === 'earn') {
                $lots[$entry->id] = ['entry' => $entry, 'balance' => BigDecimal::of($entry->points)];
            }
            if ($entry->source_entry_id && isset($lots[$entry->source_entry_id])) {
                $lots[$entry->source_entry_id]['balance'] = $lots[$entry->source_entry_id]['balance']->plus($entry->points);
            }
        }

        return $lots;
    }

    private function append(array $data): LoyaltyEntry
    {
        $existing = LoyaltyEntry::where('event_key', $data['event_key'])->first();
        if ($existing) {
            return $existing;
        }
        $company = Company::firstOrFail();

        return LoyaltyEntry::create([
            ...$data,
            'currency' => $data['currency'] ?? $company->base_currency,
            'request_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }

    private function expireDue(int $customerId): void
    {
        DB::transaction(function () use ($customerId) {
            PosCustomer::lockForUpdate()->findOrFail($customerId);
            $entries = $this->entries($customerId);
            foreach ($this->lots($entries) as $lotId => $lot) {
                $expiry = $lot['entry']->expires_on?->format('Y-m-d');
                if ($expiry && $expiry < $this->today() && $lot['balance']->isPositive()) {
                    $this->append([
                        'customer_id' => $customerId,
                        'branch_id' => $lot['entry']->branch_id,
                        'sale_id' => null,
                        'return_id' => null,
                        'source_entry_id' => $lotId,
                        'reverses_entry_id' => null,
                        'actor_id' => null,
                        'event_key' => 'expire:'.$lotId,
                        'kind' => 'expire',
                        'points' => (string) $lot['balance']->negated()->toScale(3),
                        'amount_base' => '0.0000',
                        'expires_on' => null,
                    ]);
                }
            }
        });
    }

    public function balance(PosCustomer|int $customer): string
    {
        $id = $customer instanceof PosCustomer ? $customer->id : $customer;
        $this->expireDue($id);
        $points = BigDecimal::zero();
        foreach ($this->entries($id) as $entry) {
            $points = $points->plus($entry->points);
        }

        return (string) $points->toScale(3);
    }

    public function balancesFor($customers): array
    {
        $balances = [];
        foreach ($customers as $customer) {
            $balances[$customer->id] = $this->balance($customer);
        }

        return $balances;
    }

    private function assertEligible(PosCustomer $customer): void
    {
        abort_unless(TenantModule::where('key', 'loyalty')->where('enabled', true)->exists() && $this->program()->active && $this->enrolled($customer), 422, 'This customer is not enrolled in the active rewards program.');
    }

    public function redemptionPoints(PosCustomer $customer, string $amount, string $saleTotal): BigDecimal
    {
        $this->assertEligible($customer);
        $program = $this->program();
        $value = BigDecimal::of($program->value_per_point);
        $money = BigDecimal::of($amount);
        try {
            $points = $money->dividedBy($value, 0, RoundingMode::Unnecessary);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['payments' => 'Reward tender must convert to a whole number of points.']);
        }
        $points = $points->toScale(3);
        if ($points->isLessThan($program->minimum_redemption_points)) {
            throw ValidationException::withMessages(['payments' => 'Reward redemption is below the minimum points requirement.']);
        }
        $maximum = BigDecimal::of($saleTotal)->multipliedBy($program->max_redemption_percent)->dividedBy('100', 2, RoundingMode::Down);
        if ($money->isGreaterThan($maximum)) {
            throw ValidationException::withMessages(['payments' => 'Reward points cannot cover more than the program redemption limit for this sale.']);
        }
        if (BigDecimal::of($this->balance($customer))->isLessThan($points)) {
            throw ValidationException::withMessages(['payments' => 'This customer does not have enough unexpired points.']);
        }

        return $points;
    }

    public function redeemForSale(User $actor, PosSale $sale): void
    {
        $payment = PosPayment::where('sale_id', $sale->id)->where('method', 'loyalty_points')->first();
        if (! $payment) {
            return;
        }
        if (! $sale->customer_id) {
            throw ValidationException::withMessages(['customer_id' => 'Choose an enrolled customer to redeem points.']);
        }
        $customer = PosCustomer::lockForUpdate()->findOrFail($sale->customer_id);
        $points = $this->redemptionPoints($customer, $payment->amount, $sale->total);
        $program = $this->program();
        $available = $this->lots($this->entries($customer->id));
        $lots = collect($available)->filter(fn ($lot) => $lot['balance']->isPositive() && (! $lot['entry']->expires_on || $lot['entry']->expires_on->format('Y-m-d') >= $this->today()))
            ->sortBy(fn ($lot) => $lot['entry']->expires_on?->format('Y-m-d') ?? '9999-12-31')->all();
        $remaining = $points;
        foreach ($lots as $lotId => $lot) {
            if ($remaining->isZero()) {
                break;
            }
            $take = $remaining->isLessThan($lot['balance']) ? $remaining : $lot['balance'];
            $value = $take->multipliedBy($program->value_per_point)->toScale(2, RoundingMode::Unnecessary);
            $this->append([
                'customer_id' => $customer->id,
                'branch_id' => Warehouse::findOrFail($sale->warehouse_id)->branch_id,
                'sale_id' => $sale->id,
                'return_id' => null,
                'source_entry_id' => $lotId,
                'reverses_entry_id' => null,
                'actor_id' => $actor->id,
                'event_key' => 'sale:'.$sale->id.':redeem:'.$lotId,
                'kind' => 'redeem',
                'points' => (string) $take->negated()->toScale(3),
                'amount_base' => (string) $value->toScale(4),
                'expires_on' => $lot['entry']->expires_on?->format('Y-m-d'),
            ]);
            $remaining = $remaining->minus($take);
        }
        if ($remaining->isPositive()) {
            throw ValidationException::withMessages(['payments' => 'Available points changed. Recalculate the reward payment.']);
        }
    }

    private function eligiblePromotion(string $date, string $spend): ?LoyaltyPromotion
    {
        return LoyaltyPromotion::where('active', true)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
            ->where('minimum_spend', '<=', $spend)->orderByDesc('multiplier')->orderBy('id')->first();
    }

    private function accrueAccounting(User $user, PosSale $sale, LoyaltyEntry $entry): void
    {
        if (! AccountingSetting::exists() || ! TenantModule::where('key', 'accounting')->where('enabled', true)->exists() || BigDecimal::of($entry->amount_base)->isZero()) {
            return;
        }
        $ledger = app(AccountingLedger::class);
        $ledger->system($user, [
            'book' => $sale->mode,
            'branch_id' => Warehouse::findOrFail($sale->warehouse_id)->branch_id,
            'date' => AccountingLedger::today(),
            'source_type' => 'loyalty_earn',
            'source_id' => (string) $sale->id,
            'reference' => $sale->receipt_number,
            'description' => 'Loyalty points earned on POS sale',
            'cashflow' => 'none',
            'lines' => [$ledger->line('loyalty_expense', $entry->amount_base), $ledger->line('loyalty_liability', '0', $entry->amount_base)],
        ]);
    }

    public function earnForSale(User $actor, PosSale $sale): void
    {
        if (! $sale->customer_id || ! TenantModule::where('key', 'loyalty')->where('enabled', true)->exists()) {
            return;
        }
        $customer = PosCustomer::lockForUpdate()->findOrFail($sale->customer_id);
        if (! $this->enrolled($customer) || ! $this->program()->active) {
            return;
        }
        if (LoyaltyEntry::where('event_key', 'sale:'.$sale->id.':earn')->exists()) {
            return;
        }
        $spentPoints = PosPayment::where('sale_id', $sale->id)->where('method', 'loyalty_points')->value('amount') ?? '0';
        $eligibleSpend = BigDecimal::of($sale->total)->minus($spentPoints);
        $promotion = $this->eligiblePromotion($this->today(), (string) $eligibleSpend);
        $program = $this->program();
        $multiplier = $promotion?->multiplier ?? '1.00';
        $points = $eligibleSpend->multipliedBy($program->earn_points_per_currency)->multipliedBy($multiplier)->toScale(0, RoundingMode::Down);
        if (! $points->isPositive()) {
            return;
        }
        $value = $points->multipliedBy($program->value_per_point)->toScale(2, RoundingMode::HalfUp);
        $expiry = (int) $program->expiry_days > 0 ? CarbonImmutable::parse($this->today())->addDays((int) $program->expiry_days)->toDateString() : null;
        $entry = $this->append([
            'customer_id' => $customer->id,
            'branch_id' => Warehouse::findOrFail($sale->warehouse_id)->branch_id,
            'sale_id' => $sale->id,
            'return_id' => null,
            'source_entry_id' => null,
            'reverses_entry_id' => null,
            'actor_id' => $actor->id,
            'event_key' => 'sale:'.$sale->id.':earn',
            'kind' => 'earn',
            'points' => (string) $points->toScale(3),
            'amount_base' => (string) $value->toScale(4),
            'expires_on' => $expiry,
        ]);
        $this->accrueAccounting($actor, $sale, $entry);
        Audit::record('loyalty.points_earned', (string) $entry->id, ['sale_id' => $sale->id, 'customer_id' => $customer->id, 'points' => (string) $points, 'promotion_id' => $promotion?->id]);
    }

    private function refundTotal(PosSale $sale): BigDecimal
    {
        return PosReturn::where('sale_id', $sale->id)->get(['total'])->reduce(fn (BigDecimal $sum, PosReturn $return) => $sum->plus($return->total), BigDecimal::zero());
    }

    public function reverseForReturn(User $actor, PosSale $sale, PosReturn $return): void
    {
        if (! $sale->customer_id) {
            return;
        }
        PosCustomer::lockForUpdate()->findOrFail($sale->customer_id);
        $earn = LoyaltyEntry::where('sale_id', $sale->id)->where('kind', 'earn')->first();
        $reversedValue = BigDecimal::zero();
        if ($earn) {
            $cumulative = $this->refundTotal($sale);
            $target = $cumulative->isGreaterThanOrEqualTo($sale->total)
                ? BigDecimal::of($earn->points)
                : BigDecimal::of($earn->points)->multipliedBy($cumulative)->dividedBy($sale->total, 3, RoundingMode::HalfUp);
            $prior = LoyaltyEntry::where('reverses_entry_id', $earn->id)->where('kind', 'earn_reversal')->get(['points'])->reduce(fn (BigDecimal $sum, LoyaltyEntry $row) => $sum->plus(BigDecimal::of($row->points)->abs()), BigDecimal::zero());
            $delta = $target->minus($prior);
            if ($delta->isNegative()) {
                $delta = BigDecimal::zero();
            }
            if ($delta->isPositive()) {
                $value = BigDecimal::of($earn->amount_base)->multipliedBy($delta)->dividedBy($earn->points, 4, RoundingMode::HalfUp)->toScale(4);
                $entry = $this->append([
                    'customer_id' => $sale->customer_id, 'branch_id' => Warehouse::findOrFail($return->warehouse_id)->branch_id,
                    'sale_id' => $sale->id, 'return_id' => $return->id, 'source_entry_id' => $earn->id, 'reverses_entry_id' => $earn->id,
                    'actor_id' => $actor->id, 'event_key' => 'return:'.$return->id.':earn-reversal', 'kind' => 'earn_reversal',
                    'points' => (string) $delta->negated()->toScale(3), 'amount_base' => (string) $value->abs(), 'expires_on' => $earn->expires_on?->format('Y-m-d'),
                ]);
                $reversedValue = BigDecimal::of($entry->amount_base);
            }
        }

        $redemptions = LoyaltyEntry::where('sale_id', $sale->id)->where('kind', 'redeem')->orderBy('id')->get();
        $redemptionValue = $redemptions->reduce(fn (BigDecimal $sum, LoyaltyEntry $row) => $sum->plus($row->amount_base), BigDecimal::zero());
        $loyaltyRefund = PosRefundPayment::whereIn('return_id', PosReturn::where('sale_id', $sale->id)->pluck('id'))->where('method', 'loyalty_points')->get(['amount'])->reduce(fn (BigDecimal $sum, PosRefundPayment $row) => $sum->plus($row->amount), BigDecimal::zero());
        if ($redemptionValue->isPositive() && $loyaltyRefund->isPositive()) {
            foreach ($redemptions as $redemption) {
                $originalPoints = BigDecimal::of($redemption->points)->abs();
                $target = $loyaltyRefund->isGreaterThanOrEqualTo($redemptionValue)
                    ? $originalPoints
                    : $originalPoints->multipliedBy($loyaltyRefund)->dividedBy($redemptionValue, 3, RoundingMode::HalfUp);
                $prior = LoyaltyEntry::where('reverses_entry_id', $redemption->id)->where('kind', 'redeem_restore')->get(['points'])->reduce(fn (BigDecimal $sum, LoyaltyEntry $row) => $sum->plus($row->points), BigDecimal::zero());
                $delta = $target->minus($prior);
                if ($delta->isNegative()) {
                    $delta = BigDecimal::zero();
                }
                if ($delta->isPositive()) {
                    $value = BigDecimal::of($redemption->amount_base)->multipliedBy($delta)->dividedBy($originalPoints, 4, RoundingMode::HalfUp);
                    $this->append([
                        'customer_id' => $sale->customer_id, 'branch_id' => Warehouse::findOrFail($return->warehouse_id)->branch_id,
                        'sale_id' => $sale->id, 'return_id' => $return->id, 'source_entry_id' => $redemption->source_entry_id,
                        'reverses_entry_id' => $redemption->id, 'actor_id' => $actor->id,
                        'event_key' => 'return:'.$return->id.':redeem-restore:'.$redemption->id, 'kind' => 'redeem_restore',
                        'points' => (string) $delta->toScale(3), 'amount_base' => (string) $value->toScale(4), 'expires_on' => $redemption->expires_on?->format('Y-m-d'),
                    ]);
                }
            }
        }

        if ($reversedValue->isPositive() && AccountingSetting::exists() && TenantModule::where('key', 'accounting')->where('enabled', true)->exists()) {
            $ledger = app(AccountingLedger::class);
            $ledger->system($actor, [
                'book' => $sale->mode, 'branch_id' => Warehouse::findOrFail($return->warehouse_id)->branch_id,
                'date' => AccountingLedger::today(), 'source_type' => 'loyalty_refund_reversal', 'source_id' => (string) $return->id,
                'reference' => $return->receipt_number, 'description' => 'Reverse loyalty points earned on returned sale', 'cashflow' => 'none',
                'lines' => [$ledger->line('loyalty_liability', $reversedValue), $ledger->line('loyalty_expense', '0', $reversedValue)],
            ]);
        }
    }

    public function updateProgram(User $actor, array $input): LoyaltyProgram
    {
        abort_unless($actor->canDo('loyalty.manage'), 403);
        $data = validator($input, [
            'active' => 'required|boolean', 'earn_points_per_currency' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'value_per_point' => ['required', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'gt:0', 'max:1000'],
            'max_redemption_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'minimum_redemption_points' => ['required', 'numeric', 'gte:1', 'max:10000000'],
            'expiry_days' => ['required', 'integer', 'between:0,3650'],
        ])->validate();
        $data['consent_version'] = $this->program()->consent_version;
        $program = $this->program();
        $program->update($data);
        Audit::record('loyalty.program_updated', (string) $program->id, collect($data)->except('consent_version')->all());

        return $program->refresh();
    }

    public function promotion(User $actor, array $input, ?int $id = null): LoyaltyPromotion
    {
        abort_unless($actor->canDo('loyalty.manage'), 403);
        $data = validator($input, [
            'name' => 'required|string|max:120', 'multiplier' => ['required', 'numeric', 'gt:1', 'max:10'],
            'minimum_spend' => ['required', 'numeric', 'gte:0', 'max:999999999'],
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'active' => 'required|boolean',
        ])->validate();
        $promotion = $id ? LoyaltyPromotion::findOrFail($id) : new LoyaltyPromotion;
        $promotion->fill($data);
        if (! $id) {
            $promotion->created_by = $actor->id;
        }
        $promotion->save();
        Audit::record('loyalty.promotion_saved', (string) $promotion->id, ['active' => $promotion->active, 'multiplier' => $promotion->multiplier]);

        return $promotion;
    }
}
