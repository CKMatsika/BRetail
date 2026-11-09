<?php

namespace App\Services\Paynow;

use App\Models\AccountMapping;
use App\Models\ChartAccount;
use App\Models\Company;
use App\Models\PaynowTransaction;
use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use App\Models\User;
use App\Services\AccountingLedger;
use App\Services\PosMath;
use App\Services\TenantContext;
use App\Services\TradeDocuments;
use App\Services\TradeLedger;
use App\Services\TradeSales;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class PaynowService
{
    private const INITIATE_URL = 'https://www.paynow.co.zw/interface/initiatetransaction';

    public function isConfigured(): bool
    {
        $id = config('services.paynow.integration_id');
        $key = config('services.paynow.integration_key');

        return ! empty($id) && ! empty($key);
    }

    public function isMockMode(): bool
    {
        $id = (string) config('services.paynow.integration_id');

        return ! $this->isConfigured() || in_array(strtolower($id), ['demo', 'mock', 'test'], true);
    }

    public function initiate(TradeDocument $doc, array $options = []): array
    {
        $tenantId = $doc->tenant_id;
        app(TenantContext::class)->id = $tenantId;

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->first() ?? Company::firstOrFail();
        $presented = app(TradeDocuments::class)->present($doc);

        $ledger = app(TradeLedger::class);
        $amount = $doc->kind === 'invoice'
            ? (string) BigDecimal::of($ledger->outstanding($doc))
            : (string) BigDecimal::of($doc->total);

        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            return [
                'success' => false,
                'message' => 'This document has no outstanding balance.',
            ];
        }

        $formattedAmount = number_format((float) $amount, 2, '.', '');
        $reference = 'PAY-' . $doc->id . '-' . strtoupper(Str::random(6));

        $email = $options['email'] ?? $presented['customer_email'] ?? $company->settings['contact_email'] ?? 'billing@bretail.local';
        $phone = $options['phone'] ?? $presented['customer_phone'] ?? null;

        $returnUrl = URL::temporarySignedRoute('portal.document.public', now()->addDays(7), [
            'id' => $doc->id,
            'payment' => 'return',
            'ref' => $reference,
        ]);
        $resultUrl = route('portal.paynow.webhook');

        $integrationId = config('services.paynow.integration_id');
        $integrationKey = config('services.paynow.integration_key');

        if ($this->isMockMode()) {
            // Local development or testing mock checkout
            $mockBrowserUrl = route('portal.paynow.mock-checkout', ['ref' => $reference]);
            $mockPollUrl = route('portal.paynow.check', ['ref' => $reference]);

            $tx = PaynowTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'document_id' => $doc->id,
                'reference' => $reference,
                'amount' => $formattedAmount,
                'currency' => $company->base_currency,
                'email' => $email,
                'phone' => $phone,
                'status' => 'initiated',
                'browser_url' => $mockBrowserUrl,
                'poll_url' => $mockPollUrl,
                'raw_response' => ['mock' => true],
            ]);

            return [
                'success' => true,
                'transaction_id' => $tx->id,
                'reference' => $reference,
                'browser_url' => $mockBrowserUrl,
                'poll_url' => $mockPollUrl,
                'amount' => $formattedAmount,
                'currency' => $company->base_currency,
                'is_mock' => true,
            ];
        }

        $payload = [
            'id' => $integrationId,
            'reference' => $reference,
            'amount' => $formattedAmount,
            'additionalinfo' => "{$presented['number']} - {$company->name}",
            'returnurl' => $returnUrl,
            'resulturl' => $resultUrl,
            'authemail' => $email,
            'status' => 'Message',
        ];

        $payload['hash'] = $this->generateHash($payload, $integrationKey);

        try {
            $response = Http::asForm()->timeout(15)->post(self::INITIATE_URL, $payload);
            parse_str($response->body(), $result);

            if (($result['status'] ?? '') !== 'Ok') {
                $errorMsg = $result['error'] ?? 'Paynow failed to initiate transaction.';
                Log::warning('Paynow initiate failed', ['error' => $errorMsg, 'response' => $response->body()]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            if (! $this->verifyHash($result, $integrationKey)) {
                Log::error('Paynow initiate invalid response hash', ['result' => $result]);

                return [
                    'success' => false,
                    'message' => 'Paynow security signature mismatch.',
                ];
            }

            $tx = PaynowTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'document_id' => $doc->id,
                'reference' => $reference,
                'amount' => $formattedAmount,
                'currency' => $company->base_currency,
                'email' => $email,
                'phone' => $phone,
                'status' => 'initiated',
                'browser_url' => $result['browserurl'] ?? null,
                'poll_url' => $result['pollurl'] ?? null,
                'raw_response' => $result,
            ]);

            return [
                'success' => true,
                'transaction_id' => $tx->id,
                'reference' => $reference,
                'browser_url' => $result['browserurl'],
                'poll_url' => $result['pollurl'] ?? null,
                'amount' => $formattedAmount,
                'currency' => $company->base_currency,
                'is_mock' => false,
            ];
        } catch (\Throwable $e) {
            Log::error('Paynow initiate error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Unable to reach Paynow payment gateway: ' . $e->getMessage(),
            ];
        }
    }

    public function handleWebhook(array $data): array
    {
        $reference = $data['reference'] ?? null;
        if (! $reference) {
            return ['success' => false, 'message' => 'Missing transaction reference'];
        }

        $tx = PaynowTransaction::withoutGlobalScopes()->where('reference', $reference)->first();
        if (! $tx) {
            return ['success' => false, 'message' => 'Unknown transaction reference'];
        }

        $integrationKey = config('services.paynow.integration_key');
        if (! $this->isMockMode() && ! $this->verifyHash($data, $integrationKey)) {
            Log::warning('Paynow webhook hash mismatch', ['data' => $data]);

            return ['success' => false, 'message' => 'Invalid signature'];
        }

        $status = strtolower($data['status'] ?? '');
        $paynowRef = $data['paynowreference'] ?? null;

        if (in_array($status, ['paid', 'awaiting delivery'], true)) {
            if ($tx->status === 'paid') {
                return ['success' => true, 'status' => 'already_settled'];
            }

            $settlement = $this->processSettlement($tx, $paynowRef);

            $tx->update([
                'status' => 'paid',
                'paynow_reference' => $paynowRef,
                'settlement_document_id' => $settlement?->id,
                'raw_response' => array_merge($tx->raw_response ?? [], $data),
            ]);

            return ['success' => true, 'status' => 'paid'];
        }

        if (in_array($status, ['cancelled', 'failed', 'disputed', 'refunded'], true)) {
            $tx->update([
                'status' => $status,
                'paynow_reference' => $paynowRef,
                'raw_response' => array_merge($tx->raw_response ?? [], $data),
            ]);

            return ['success' => true, 'status' => $status];
        }

        return ['success' => true, 'status' => $status ?: 'pending'];
    }

    public function pollStatus(PaynowTransaction $tx): array
    {
        if ($tx->status === 'paid') {
            return ['status' => 'paid', 'transaction' => $tx];
        }

        if ($this->isMockMode()) {
            return ['status' => $tx->status, 'transaction' => $tx];
        }

        if (empty($tx->poll_url)) {
            return ['status' => $tx->status, 'transaction' => $tx];
        }

        try {
            $response = Http::timeout(10)->post($tx->poll_url);
            parse_str($response->body(), $result);

            $integrationKey = config('services.paynow.integration_key');
            if (! $this->verifyHash($result, $integrationKey)) {
                return ['status' => $tx->status, 'error' => 'Hash mismatch'];
            }

            $this->handleWebhook($result);
            $tx->refresh();

            return ['status' => $tx->status, 'transaction' => $tx];
        } catch (\Throwable $e) {
            Log::warning('Paynow poll error: ' . $e->getMessage());

            return ['status' => $tx->status, 'error' => $e->getMessage()];
        }
    }

    public function processSettlement(PaynowTransaction $tx, ?string $paynowRef = null): ?TradeDocument
    {
        $tenantId = $tx->tenant_id;
        app(TenantContext::class)->id = $tenantId;

        $doc = TradeDocument::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($tx->document_id);

        // Find active tenant actor
        $user = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('active', true)->first();
        if (! $user) {
            Log::error("No active user found for tenant {$tenantId} to record Paynow payment.");

            return null;
        }

        $account = $this->paynowAccount($tenantId);
        $today = app(AccountingLedger::class)->today();
        $reference = 'Paynow: ' . ($paynowRef ?: $tx->reference);

        if ($doc->kind === 'quote') {
            // Check if quotation already has an invoice
            $invoice = TradeDocument::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('parent_id', $doc->id)
                ->where('kind', 'invoice')
                ->first();

            if (! $invoice) {
                $invoice = app(TradeSales::class)->invoice($user, $doc->id, [
                    'request_id' => (string) Str::uuid(),
                    'date' => $today,
                    'accepted' => true,
                ]);
            }

            $targetDoc = $invoice;
        } else {
            $targetDoc = $doc;
        }

        $outstanding = app(TradeLedger::class)->outstanding($targetDoc);
        $settleAmount = PosMath::money(BigDecimal::of($tx->amount)->min(BigDecimal::of($outstanding)));

        if (BigDecimal::of($settleAmount)->isPositive()) {
            return app(TradeLedger::class)->settle($user, $targetDoc->id, [
                'request_id' => (string) Str::uuid(),
                'date' => $today,
                'amount' => $settleAmount,
                'account_id' => $account->id,
                'reference' => $reference,
            ]);
        }

        return null;
    }

    public function paynowAccount(int $tenantId): ChartAccount
    {
        app(TenantContext::class)->id = $tenantId;

        if (AccountMapping::where('role', 'paynow')->exists()) {
            return app(AccountingLedger::class)->account('paynow');
        }

        $existing = ChartAccount::where('is_cash', true)->where('active', true)->where('code', '!=', 'CASH')->first();
        if ($existing) {
            AccountMapping::create(['role' => 'paynow', 'account_id' => $existing->id]);

            return $existing;
        }

        $code = '1090';
        while (ChartAccount::where('code', $code)->exists()) {
            $code .= 'P';
        }

        $account = ChartAccount::create([
            'code' => $code,
            'name' => 'Paynow Online Payments',
            'type' => 'asset',
            'report_group' => 'Cash and cash equivalents',
            'is_cash' => true,
            'active' => true,
        ]);

        AccountMapping::create(['role' => 'paynow', 'account_id' => $account->id]);

        return $account;
    }

    public function generateHash(array $values, string $key): string
    {
        $string = '';
        foreach ($values as $name => $value) {
            if (strtolower((string) $name) !== 'hash') {
                $string .= (string) $value;
            }
        }
        $string .= $key;

        return strtoupper(hash('sha512', $string));
    }

    public function verifyHash(array $data, string $key): bool
    {
        if (empty($data['hash'])) {
            return false;
        }

        $receivedHash = strtoupper((string) $data['hash']);
        $calculatedHash = $this->generateHash($data, $key);

        return hash_equals($calculatedHash, $receivedHash);
    }
}

