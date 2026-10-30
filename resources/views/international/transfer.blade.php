<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transfer {{ $transfer->reference }}</title>
    <style>
        body { font: 14px system-ui, sans-serif; color: #19382a; margin: 32px; }
        main { max-width: 900px; margin: auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border-bottom: 1px solid #d7e1da; padding: 10px; text-align: left; }
        button { padding: 9px 14px; }
        @media print { body { margin: 12mm; } button { display: none; } }
    </style>
</head>
<body>
<main>
    <h1>{{ $company->name }}</h1>
    <h2>Branch transfer {{ $transfer->reference }}</h2>
    <p>{{ strtoupper($transfer->book) }} · {{ $transfer->status }} · Created {{ $transfer->date }}</p>
    <p>From: {{ $source->name }}<br>To: {{ $destination->name }}</p>
    <h3>Requested quantities</h3>
    <table>
        <thead><tr><th>SKU</th><th>Product</th><th>Quantity</th></tr></thead>
        <tbody>
        @foreach($transfer->items as $item)
            <tr><td>{{ $variants[$item['variant_id']]->sku ?? '' }}</td><td>{{ $variants[$item['variant_id']]->name ?? '' }}</td><td>{{ $item['quantity'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <h3>Transfer events</h3>
    <table>
        <thead><tr><th>Date</th><th>Event</th><th>SKU</th><th>Quantity</th><th>Base carrying value</th><th>Reason</th></tr></thead>
        <tbody>
        @foreach($events as $event)
            @foreach($event->items as $item)
                <tr><td>{{ $event->date }}</td><td>{{ $event->kind }}</td><td>{{ $variants[$item['variant_id']]->sku ?? '' }}</td><td>{{ $item['quantity'] }}</td><td>{{ $item['value'] }} {{ $company->base_currency }}</td><td>{{ $item['reason'] ?? '' }}</td></tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
    <button onclick="window.print()">Print transfer</button>
</main>
</body>
</html>
