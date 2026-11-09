<?php

namespace App\Http\Controllers;

use App\Models\PaynowTransaction;
use App\Models\TradeDocument;
use App\Services\Paynow\PaynowService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaynowController extends Controller
{
    public function initiate(Request $r, string $id)
    {
        $doc = TradeDocument::withoutGlobalScopes()->findOrFail($id);

        $service = app(PaynowService::class);
        $result = $service->initiate($doc, [
            'email' => $r->input('email'),
            'phone' => $r->input('phone'),
        ]);

        if (! $result['success']) {
            if ($r->expectsJson()) {
                return response()->json($result, 422);
            }

            return back()->with('error', $result['message']);
        }

        if ($r->expectsJson()) {
            return response()->json($result);
        }

        return redirect()->away($result['browser_url']);
    }

    public function webhook(Request $r)
    {
        $data = $r->all();
        Log::info('Paynow IPN Webhook received', ['payload' => $data]);

        $service = app(PaynowService::class);
        $result = $service->handleWebhook($data);

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    public function checkStatus(Request $r, string $ref)
    {
        $tx = PaynowTransaction::withoutGlobalScopes()->where('reference', $ref)->firstOrFail();

        $service = app(PaynowService::class);
        $result = $service->pollStatus($tx);

        return response()->json($result);
    }

    public function mockCheckout(Request $r)
    {
        $ref = $r->query('ref');
        $tx = PaynowTransaction::withoutGlobalScopes()->where('reference', $ref)->firstOrFail();
        $doc = TradeDocument::withoutGlobalScopes()->where('tenant_id', $tx->tenant_id)->findOrFail($tx->document_id);

        return view('trade.paynow-mock', [
            'transaction' => $tx,
            'document' => $doc,
        ]);
    }

    public function mockComplete(Request $r)
    {
        $ref = $r->input('ref');
        $status = $r->input('status', 'Paid');
        $tx = PaynowTransaction::withoutGlobalScopes()->where('reference', $ref)->firstOrFail();

        $service = app(PaynowService::class);
        $service->handleWebhook([
            'reference' => $ref,
            'paynowreference' => 'TEST-' . rand(100000, 999999),
            'amount' => (string) $tx->amount,
            'status' => $status,
        ]);

        $returnUrl = $tx->document_id
            ? route('portal.document.public', ['id' => $tx->document_id, 'payment' => 'return', 'ref' => $ref])
            : url('/');

        return redirect($returnUrl)->with('status', 'Payment simulated successfully.');
    }
}

