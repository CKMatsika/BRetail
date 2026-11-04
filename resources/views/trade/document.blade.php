@php
    $money = fn ($value) => number_format((float) ($value ?? 0), 2, '.', ',');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) ($value ?? 0), 3, '.', ','), '0'), '.');
    $dateLabel = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y') : '—';
    $currency = $company->base_currency;
    $originalCurrency = $document['currency_snapshot']['currency'] ?? $currency;
    $title = ucwords($kind);
    $due = $document['due_on'] ?? $document['expected_on'] ?? null;
    $dueLabel = str_contains(strtolower($kind), 'quote') ? 'Valid until' : (str_contains(strtolower($kind), 'order') ? 'Expected on' : 'Due date');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} · {{ $document['number'] }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f4;color:#25313b;font:14px/1.55 Arial,Helvetica,sans-serif}.toolbar{max-width:1000px;margin:24px auto 16px;display:flex;align-items:center;justify-content:space-between;padding:0 12px;color:#63717c}.toolbar button{border:0;border-radius:6px;background:#193e35;color:white;padding:11px 20px;font:600 14px Arial;cursor:pointer}.sheet{max-width:1000px;margin:0 auto 40px;background:white;padding:48px 52px;box-shadow:0 4px 24px #20303b0c;border:1px solid #e1e7eb;min-height:850px}.header{display:flex;justify-content:space-between;gap:32px;padding-bottom:30px;border-bottom:3px solid #193e35}.brand{max-width:60%}.brand img{max-height:76px!important;margin:0 0 14px!important}.brand h1{font-size:21px;line-height:1.35;margin:0;font-weight:700;color:#193e35}.document-heading{text-align:right}.document-heading h2{font-size:30px;letter-spacing:2px;text-transform:uppercase;margin:0;color:#193e35;line-height:1.2}.number{font-size:15px;margin-top:10px;color:#4e5d68}.currency{font-size:12px;color:#6a7780;margin-top:7px}.details{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin:30px 0 34px}.label{font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:1.4px;color:#78858e}.party{font-size:18px;font-weight:600;margin-top:7px}.metadata{margin:0;display:grid;grid-template-columns:auto auto;gap:8px 20px;justify-content:end}.metadata dt{color:#6a7780}.metadata dd{margin:0;text-align:right;font-weight:600}.notice{background:#fff6e4;border-left:3px solid #b77817;padding:12px 16px;margin-top:20px}.items{width:100%;border-collapse:collapse;font-size:13px}.items th{background:#f1f5f4;color:#40594f;font-size:10px;letter-spacing:.6px;text-transform:uppercase;padding:13px 10px;text-align:left;border-bottom:1px solid #dbe5df}.items td{padding:18px 10px;border-bottom:1px solid #e4e9eb;vertical-align:top}.items .numeric{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.description{font-weight:600;min-width:160px}.sku,.basis{display:block;font-size:11px;color:#78858e;font-weight:normal;margin-top:4px}.summary{display:flex;justify-content:flex-end;margin-top:24px}.totals{width:330px;margin:0}.totals div{display:flex;justify-content:space-between;gap:20px;padding:8px 12px}.totals dt{color:#63717c}.totals dd{margin:0;font-variant-numeric:tabular-nums}.totals .grand{background:#193e35;color:white;margin-top:10px;padding:15px 12px;font-size:18px;font-weight:bold}.grand dt{color:white}.conversion{margin-top:18px;text-align:right;color:#6a7780;font-size:12px}.notes{margin-top:40px;border-top:1px solid #e4e9eb;padding-top:20px}.notes p{white-space:pre-line;margin:8px 0 0;color:#55636e}.footer{border-top:1px solid #e4e9eb;margin-top:48px;padding-top:16px;display:flex;justify-content:space-between;gap:20px;font-size:11px;color:#819099}
@media(max-width:650px){.sheet{padding:25px 18px;margin-bottom:0;min-height:0}.header{gap:15px}.brand h1{font-size:17px}.document-heading h2{font-size:21px}.number{font-size:12px}.details{grid-template-columns:1fr}.metadata{justify-content:stretch}.items th,.items td{padding:10px 5px}.items{font-size:11px}.description{min-width:0}.items .numeric{white-space:normal}.totals{width:100%}.footer{flex-wrap:wrap}}
@page{size:A4;margin:16mm}@media print{body{background:white;font-size:11px}.toolbar{display:none}.sheet{border:0;box-shadow:none;margin:0;padding:0;min-height:0;max-width:none}.header{break-inside:avoid}.items thead{display:table-header-group}.items tr,.summary,.notes,.footer{break-inside:avoid}*{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
</head>
<body>
<div class="toolbar"><span>{{ $title }} preview</span><button onclick="window.print()">Print document</button></div>
<main class="sheet">
<header class="header">
<div class="brand">@include('partials.company-logo')<h1>{{ $company->name }}</h1></div>
<div class="document-heading"><h2>{{ $title }}</h2><div class="number">{{ $document['number'] }}</div><div class="currency">Currency · {{ $currency }}</div></div>
</header>
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
<footer class="footer"><span>{{ $company->name }}</span><span>{{ $document['number'] }} · {{ $currency }}</span></footer>
</main>
</body>
</html>
