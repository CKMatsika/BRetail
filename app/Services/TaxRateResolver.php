<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Validation\ValidationException;

final class TaxRateResolver
{
    public static function forClass(Company $company, string $taxClass): string
    {
        if ($taxClass === 'standard') {
            return (string) ($company->settings['tax_rate'] ?? '0');
        }
        if (in_array($taxClass, ['zero', 'exempt'], true)) {
            return '0';
        }
        foreach ($company->settings['tax_rates'] ?? [] as $tax) {
            if (($tax['id'] ?? null) === $taxClass) {
                return ($tax['type'] ?? 'standard') === 'standard' ? (string) ($tax['rate'] ?? '0') : '0';
            }
        }

        throw ValidationException::withMessages(['tax_class' => 'This item tax profile is no longer configured. Select an available tax profile and save the item.']);
    }
}
