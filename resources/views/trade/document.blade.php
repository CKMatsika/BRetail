@php
    $money = fn ($value) => number_format((float) ($value ?? 0), 2, '.', ',');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) ($value ?? 0), 3, '.', ','), '0'), '.');
    $dateLabel = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y') : '—';
    $companyDetails = $company->settings ?? [];
    $showBanking = in_array(strtolower($kind), ['invoice', 'quote', 'quotation'], true) && trim($companyDetails['banking_details'] ?? '') !== '';
    $currency = $company->base_currency;
    $originalCurrency = $document['currency_snapshot']['currency'] ?? $currency;
    $title = ucwords($kind);
    $due = $document['due_on'] ?? $document['expected_on'] ?? null;
    $dueLabel = str_contains(strtolower($kind), 'quote') ? 'Valid until' : (str_contains(strtolower($kind), 'order') ? 'Expected on' : 'Due date');
    $isQuoteOrInvoice = in_array(strtolower($kind), ['invoice', 'quote', 'quotation'], true);
    $docTotal = (float) ($document['total'] ?? 0);
    $docOutstanding = isset($outstanding) ? (float) $outstanding : $docTotal;
    $documentPaid = ($isPaid ?? false) || (strtolower($kind) === 'invoice' && $docOutstanding <= 0);
    $canPayOnline = $isQuoteOrInvoice && ! $documentPaid && $docTotal > 0;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} · {{ $document['number'] }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f4;color:#25313b;font:14px/1.55 Arial,Helvetica,sans-serif}.toolbar{max-width:1000px;margin:24px auto 16px;display:flex;align-items:center;justify-content:space-between;padding:0 12px;color:#63717c}.toolbar button{border:0;border-radius:6px;background:#193e35;color:white;padding:11px 20px;font:600 14px Arial;cursor:pointer}.sheet{max-width:1000px;margin:0 auto 40px;background:white;padding:48px 52px;box-shadow:0 4px 24px #20303b0c;border:1px solid #e1e7eb;min-height:850px}.header{display:flex;justify-content:space-between;gap:32px;padding-bottom:30px;border-bottom:3px solid #193e35}.brand{display:flex;align-items:flex-start;gap:20px;flex:1;min-width:0}.brand-logo{flex:0 0 90px}.brand-logo img{max-width:90px!important;max-height:100px!important;margin:0!important}.company-details{min-width:0}.company-contact{font-size:12px;line-height:1.65;color:#55636e;margin-top:8px;overflow-wrap:anywhere}.company-address{white-space:pre-line}.company-identifiers{margin-top:7px;font-size:11px;color:#55636e}.company-identifiers span{display:block}.document-heading{flex:0 0 auto;max-width:35%}.brand h1{font-size:20px;line-height:1.35;margin:0;font-weight:700;color:#193e35}.document-heading{text-align:right}.document-heading h2{font-size:30px;letter-spacing:2px;text-transform:uppercase;margin:0;color:#193e35;line-height:1.2}.number{font-size:15px;margin-top:10px;color:#4e5d68}.currency{font-size:12px;color:#6a7780;margin-top:7px}.details{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin:30px 0 34px}.label{font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:1.4px;color:#78858e}.party{font-size:18px;font-weight:600;margin-top:7px}.metadata{margin:0;display:grid;grid-template-columns:auto auto;gap:8px 20px;justify-content:end}.metadata dt{color:#6a7780}.metadata dd{margin:0;text-align:right;font-weight:600}.notice{background:#fff6e4;border-left:3px solid #b77817;padding:12px 16px;margin-top:20px}.items{width:100%;border-collapse:collapse;font-size:13px}.items th{background:#f1f5f4;color:#40594f;font-size:10px;letter-spacing:.6px;text-transform:uppercase;padding:13px 10px;text-align:left;border-bottom:1px solid #dbe5df}.items td{padding:18px 10px;border-bottom:1px solid #e4e9eb;vertical-align:top}.items .numeric{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.description{font-weight:600;min-width:160px}.sku,.basis{display:block;font-size:11px;color:#78858e;font-weight:normal;margin-top:4px}.summary{display:flex;justify-content:flex-end;margin-top:24px}.totals{width:330px;margin:0}.totals div{display:flex;justify-content:space-between;gap:20px;padding:8px 12px}.totals dt{color:#63717c}.totals dd{margin:0;font-variant-numeric:tabular-nums}.totals .grand{background:#193e35;color:white;margin-top:10px;padding:15px 12px;font-size:18px;font-weight:bold}.grand dt{color:white}.conversion{margin-top:18px;text-align:right;color:#6a7780;font-size:12px}.notes{margin-top:40px;border-top:1px solid #e4e9eb;padding-top:20px}.notes p{white-space:pre-line;margin:8px 0 0;color:#55636e}.footer{border-top:1px solid #e4e9eb;margin-top:48px;padding-top:16px;display:flex;justify-content:space-between;gap:20px;font-size:11px;color:#819099}
@media(max-width:650px){.sheet{padding:25px 18px;margin-bottom:0;min-height:0}.header{gap:20px;flex-wrap:wrap}.brand{flex-basis:100%;gap:14px}.brand-logo{flex-basis:64px}.brand-logo img{max-width:64px!important;max-height:80px!important}.document-heading{max-width:none;text-align:left}.company-contact{font-size:11px}.brand h1{font-size:17px}.document-heading h2{font-size:21px}.number{font-size:12px}.details{grid-template-columns:1fr}.metadata{justify-content:stretch}.items th,.items td{padding:10px 5px}.items{font-size:11px}.description{min-width:0}.items .numeric{white-space:normal}.totals{width:100%}.footer{flex-wrap:wrap}}
.banking{margin-top:28px;border:1px solid #dfe3e8;border-radius:4px;padding:18px 20px;background:#f8f9fa;break-inside:avoid}.banking p{margin:9px 0 0;white-space:pre-line;font-size:12px;line-height:1.75;color:#40505d;overflow-wrap:anywhere}.banking .label{color:#40505d}
@page{size:A4;margin:16mm}@media print{body{background:white;font-size:11px}.toolbar{display:none}.sheet{border:0;box-shadow:none;margin:0;padding:0;min-height:0;max-width:none}.header{break-inside:avoid}.items thead{display:table-header-group}.items tr,.summary,.notes,.footer{break-inside:avoid}*{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
</head>
<body>
@if(empty($pdf_mode))<div class="toolbar">
    <span>{{ $title }} · {{ $document['number'] }}</span>
    <div style="display:flex;gap:10px;align-items:center;">
        @if($canPayOnline)
            <form method="POST" action="{{ route('portal.paynow.initiate', $document['id']) }}" style="margin:0;display:inline;">
                @csrf
                <button type="submit" style="border:0;border-radius:6px;background:#008751;color:white;padding:10px 18px;font:700 13px Arial;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    <span>⚡ Pay Online (Paynow)</span>
                </button>
            </form>
        @endif
        @php
            $waPhone = preg_replace('/[^0-9]/', '', $document['customer_phone'] ?? '');
            $shareText = rawurlencode("Hello, please find your {$title} #{$document['number']} totaling {$currency} " . number_format((float) ($document['total'] ?? 0), 2) . ". View here: " . url()->current());
        @endphp
        <a href="https://wa.me/{{ $waPhone }}?text={{ $shareText }}" target="_blank" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;border-radius:6px;background:#25D366;color:white;padding:10px 16px;font:600 13px Arial;">
            <span>Share on WhatsApp</span>
        </a>
        <button onclick="window.print()">Print / Save PDF</button>
        @if(!empty($is_public) && !empty($download_url))<a href="{{ $download_url }}" style="text-decoration:none;border-radius:6px;background:#2563eb;color:white;padding:11px 18px;font:600 14px Arial;">Download PDF</a>@endif
    </div>
</div>@endif
<main class="sheet">
@if(empty($pdf_mode) && !empty($paymentNotice))
    <div style="background:{{ $paymentNotice['type'] === 'success' ? '#ecfdf5' : '#eff6ff' }};border:1px solid {{ $paymentNotice['type'] === 'success' ? '#a7f3d0' : '#bfdbfe' }};color:{{ $paymentNotice['type'] === 'success' ? '#065f46' : '#1e40af' }};padding:14px 18px;border-radius:6px;margin-bottom:24px;font-weight:600;display:flex;align-items:center;gap:10px;">
        <span>{{ $paymentNotice['message'] }}</span>
    </div>
@endif
<header class="header">
<div class="brand">
@if($company->document_logo)<div class="brand-logo">@include('partials.company-logo')</div>@endif
<div class="company-details">
<h1>{{ $company->name }}</h1>
<div class="company-contact">
@if(!empty($companyDetails['address']))<div class="company-address">{{ $companyDetails['address'] }}</div>@endif
@if(!empty($companyDetails['phone']) || !empty($companyDetails['alternative_phone']))<div>Tel: {{ implode(' / ', array_filter([$companyDetails['phone'] ?? '', $companyDetails['alternative_phone'] ?? ''])) }}</div>@endif
@if(!empty($companyDetails['contact_email']))<div>{{ $companyDetails['contact_email'] }}</div>@endif
</div>
<div class="company-identifiers">
@if(!empty($companyDetails['registration']))<span>Registration No: {{ $companyDetails['registration'] }}</span>@endif
@if(!empty($companyDetails['tax_id']))<span>Tax ID: {{ $companyDetails['tax_id'] }}</span>@endif
@if(!empty($companyDetails['vat_number']))<span>VAT Number: {{ $companyDetails['vat_number'] }}</span>@endif
</div>
</div>
</div>
<div class="document-heading">
    <h2>{{ $title }}</h2>
    <div class="number">{{ $document['number'] }}</div>
    <div class="currency">Currency · {{ $currency }}</div>
    @if($isPaid ?? false)
        <div style="display:inline-block;border:2px solid #059669;color:#059669;font-weight:800;letter-spacing:1.5px;padding:4px 12px;border-radius:4px;text-transform:uppercase;font-size:12px;margin-top:8px;">✓ PAID IN FULL</div>
    @endif
</div>
</header>
@if($canPayOnline && empty($pdf_mode))
<div style="margin:20px 0;padding:16px 20px;background:#f0fdf4;border:2px solid #008751;border-radius:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;break-inside:avoid;">
    <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#166534;">Online Payment Available</div>
        <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:2px;">Pay with Paynow (EcoCash, OneMoney, InnBucks, Card)</div>
        <div style="font-size:13px;color:#334155;margin-top:2px;">Amount to pay: <strong style="color:#008751;font-size:15px;">{{ $currency }} {{ $money($docOutstanding) }}</strong></div>
    </div>
    <form method="POST" action="{{ route('portal.paynow.initiate', $document['id']) }}" style="margin:0;">
        @csrf
        <button type="submit" style="background:#008751;color:#fff;border:0;border-radius:6px;padding:12px 24px;font:700 14px Arial;cursor:pointer;box-shadow:0 4px 12px rgba(0,135,81,0.25);">
            ⚡ Pay {{ $currency }} {{ $money($docOutstanding) }} Now →
        </button>
    </form>
</div>
@endif
@if($document['superseded'] ?? false)<p class="notice">Previous version · This document has been edited. Use the latest version for current amounts.</p>@endif
@if(($document['book'] ?? '') !== 'live')<p class="notice">{{ strtoupper($document['book'] ?? 'training') }} · Training document</p>@endif
<section class="details">
<div><div class="label">{{ isset($document['supplier_name']) ? 'Supplier' : 'Bill to' }}</div><div class="party">{{ $document['party_name'] ?? $document['supplier_name'] ?? '—' }}</div></div>
<dl class="metadata"><dt>Issue date</dt><dd>{{ $dateLabel($document['date']) }}</dd>@if($due)<dt>{{ $dueLabel }}</dt><dd>{{ $dateLabel($due) }}</dd>@endif @if(!empty($document['reference']))<dt>Reference</dt><dd>{{ $document['reference'] }}</dd>@endif</dl>
</section>
<table class="items"><thead><tr><th>Description</th><th class="numeric">Qty</th><th class="numeric">Unit price @if($originalCurrency !== $currency)<br>({{ $originalCurrency }})@endif</th><th class="numeric">Net ({{ $currency }})</th><th class="numeric">VAT ({{ $currency }})</th></tr></thead><tbody>
@foreach($document['items'] as $item)
<tr><td class="description">{{ $item['description'] ?? $item['sku'] ?? 'Item' }}@if(!empty($item['sku']))<span class="sku">{{ $item['sku'] }}</span>@endif</td><td class="numeric">{{ $quantity($item['quantity'] ?? 0) }}</td><td class="numeric">{{ $money($item['original']['unit_price'] ?? $item['original_unit_cost'] ?? $item['unit_price'] ?? $item['unit_cost'] ?? 0) }}@if(isset($item['unit_price']))<span class="basis">{{ !empty($item['prices_include_vat']) ? 'VAT included' : 'VAT excluded' }}</span>@endif</td><td class="numeric">{{ $money($item['net'] ?? $item['value'] ?? 0) }}</td><td class="numeric">{{ $money($item['tax'] ?? 0) }}</td></tr>
@endforeach
</tbody></table>
<div class="summary"><dl class="totals">@if(isset($document['net']))<div><dt>Subtotal (excl. VAT)</dt><dd>{{ $money($document['net']) }}</dd></div><div><dt>VAT</dt><dd>{{ $money($document['tax']) }}</dd></div>@endif @if(isset($document['total']))<div class="grand"><dt>Total {{ $currency }}</dt><dd>{{ $money($document['total']) }}</dd></div>@endif</dl></div>
@if(!empty($document['currency_snapshot']))<div class="conversion">Original total: {{ $originalCurrency }} {{ $money($document['currency_snapshot']['original_amount']) }}<br>Exchange rate: {{ $document['currency_snapshot']['exchange_rate'] }} {{ $currency }} per {{ $originalCurrency }}</div>@endif
@if(!empty($document['notes']))<section class="notes"><div class="label">Notes</div><p>{{ $document['notes'] }}</p></section>@endif
@if($canPayOnline)
<section id="paynow" style="margin-top:28px;border:2px solid #008751;border-radius:8px;padding:20px 24px;background:#f0fdf4;break-inside:avoid;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#166534;">Instant Online Payment</div>
            <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:2px;">Pay with Paynow Zimbabwe</div>
            <div style="font-size:12px;color:#475569;margin-top:4px;">EcoCash · OneMoney · InnBucks · Zimswitch · Visa · Mastercard</div>
            <div style="font-size:13px;color:#1e293b;margin-top:6px;">Outstanding: <strong>{{ $currency }} {{ $money($docOutstanding) }}</strong></div>
        </div>
        <form method="POST" action="{{ route('portal.paynow.initiate', $document['id']) }}" style="margin:0;">
            @csrf
            <button type="submit" style="background:#008751;color:#fff;border:0;border-radius:6px;padding:12px 24px;font:700 14px Arial;cursor:pointer;box-shadow:0 4px 12px rgba(0,135,81,0.25);">
                ⚡ Pay {{ $currency }} {{ $money($docOutstanding) }} Now →
            </button>
        </form>
    </div>
</section>
@endif
@if($showBanking)
<section class="banking" aria-label="Banking details"><div class="label">Banking details</div><p>{{ $companyDetails['banking_details'] }}</p></section>
@endif
<footer class="footer"><span>{{ $company->name }}</span><span>{{ $document['number'] }} · {{ $currency }}</span></footer>
</main>
</body>
</html>
