<?php

namespace App\Services;

use App\Models\TradeAllocation;
use App\Models\TradeDocument;
use App\Models\TradeDocumentDetail;

final class TradeDocuments
{
    public function present(TradeDocument $doc): array
    {
        $versions = $doc->versions();
        $root = end($versions);
        $details = TradeDocumentDetail::where('document_id', $doc->id)->latest('id')->first();
        $superseded = ! TradeDocument::current()->whereKey($doc->id)->exists();
        $customer = $doc->customer_id ? \App\Models\PosCustomer::withoutGlobalScopes()->find($doc->customer_id) : null;
        $supplier = $doc->supplier_id ? \App\Models\Supplier::withoutGlobalScopes()->find($doc->supplier_id) : null;
        $contactEmail = $customer?->email ?: $supplier?->email;
        $contactPhone = $customer?->phone ?: $supplier?->phone;

        return [...$doc->toArray(), 'number' => $root->number, 'document_id' => $root->id,
            'version' => count($versions), 'superseded' => $superseded,
            'has_history' => count($versions) > 1 || (bool) $details,
            'reference' => $details?->reference ?? $doc->reference,
            'notes' => $details ? $details->notes : $doc->notes,
            'invoice_editable' => $doc->kind === 'invoice' && ! $superseded && ! TradeAllocation::where('target_id', $doc->id)->exists(),
            'customer_email' => $contactEmail,
            'customer_phone' => $contactPhone,
        ];
    }
}
