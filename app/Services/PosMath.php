<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PosMath
{
    public const MONEY = 'regex:/^\d{1,9}(\.\d{1,2})?$/';

    public static function decimal(string $v): BigDecimal
    {
        return BigDecimal::of($v);
    }

    public static function money(BigDecimal|string $v): string
    {
        return (string) BigDecimal::of($v)->toScale(2, RoundingMode::HalfUp);
    }

    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus((string) $v);
        }

        return (string) $total->toScale($scale, RoundingMode::HalfUp);
    }

    public static function line(string $quantity, string $price, string $discount, string $taxRate, bool $inclusive = false): array
    {
        $subtotal = BigDecimal::of($quantity)->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
        $off = $subtotal->multipliedBy($discount)->dividedBy('100', 2, RoundingMode::HalfUp);
        $net = $subtotal->minus($off);
        if ($inclusive) {
            $total = $net;
            $net = $total->multipliedBy('100')->dividedBy(BigDecimal::of('100')->plus($taxRate), 2, RoundingMode::HalfUp);

            return ['subtotal' => (string) $subtotal, 'discount' => (string) $off, 'net' => (string) $net, 'tax' => (string) $total->minus($net), 'total' => (string) $total];
        }
        $tax = $net->multipliedBy($taxRate)->dividedBy('100', 2, RoundingMode::HalfUp);

        return ['subtotal' => (string) $subtotal, 'discount' => (string) $off, 'net' => (string) $net, 'tax' => (string) $tax, 'total' => (string) $net->plus($tax)];
    }

    public static function portion(string $value, string $originalQty, string $alreadyReturned, string $newQty, int $scale = 2): string
    {
        $v = BigDecimal::of($value);
        $prior = $v->multipliedBy($alreadyReturned)->dividedBy($originalQty, $scale, RoundingMode::HalfUp);
        $after = $v->multipliedBy(BigDecimal::of($alreadyReturned)->plus($newQty))->dividedBy($originalQty, $scale, RoundingMode::HalfUp);

        return (string) $after->minus($prior);
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
