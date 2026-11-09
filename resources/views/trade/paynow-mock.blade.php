<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paynow Zimbabwe · Checkout Simulation</title>
<style>
body{margin:0;padding:24px;background:#f3f6f8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1e293b}
.card{max-width:480px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.06);border:1px solid #e2e8f0;overflow:hidden}
.header{background:#004b87;color:#fff;padding:24px;text-align:center}
.header h1{margin:0;font-size:20px;letter-spacing:1px}
.header p{margin:6px 0 0;font-size:13px;opacity:0.85}
.body{padding:24px}
.summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:20px}
.summary-row{display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px}
.summary-row:last-child{margin-bottom:0}
.summary-row.total{border-top:1px dashed #cbd5e1;padding-top:10px;margin-top:10px;font-weight:700;font-size:16px;color:#0f172a}
.methods{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px}
.method{border:2px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center;font-size:13px;font-weight:600;color:#334155;background:#fff}
.method.selected{border-color:#004b87;background:#f0f7ff;color:#004b87}
.badge-demo{display:inline-block;background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:9999px;font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:12px}
.btn{display:block;width:100%;border:0;border-radius:8px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;text-align:center;text-decoration:none}
.btn-pay{background:#008751;color:#fff;margin-bottom:10px}
.btn-pay:hover{background:#006d41}
.btn-cancel{background:#f1f5f9;color:#64748b}
.btn-cancel:hover{background:#e2e8f0}
</style>
</head>
<body>
<div class="card">
    <div class="header">
        <span class="badge-demo">Paynow Sandbox Simulation</span>
        <h1>Paynow Payment Gateway</h1>
        <p>EcoCash · OneMoney · InnBucks · Visa · Mastercard</p>
    </div>
    <div class="body">
        <div class="summary">
            <div class="summary-row"><span>Document</span><span>#{{ $document->display_number }}</span></div>
            <div class="summary-row"><span>Reference</span><span>{{ $transaction->reference }}</span></div>
            <div class="summary-row"><span>Customer</span><span>{{ $transaction->email ?: 'Customer' }}</span></div>
            <div class="summary-row total"><span>Amount Due</span><span>{{ $transaction->currency }} {{ number_format((float) $transaction->amount, 2) }}</span></div>
        </div>

        <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:8px">Select Payment Channel</div>
        <div class="methods">
            <div class="method selected">⚡ EcoCash</div>
            <div class="method">📱 OneMoney</div>
            <div class="method">🏦 Zimswitch / Card</div>
            <div class="method">🦌 InnBucks</div>
        </div>

        <form method="POST" action="{{ route('portal.paynow.mock-complete') }}">
            @csrf
            <input type="hidden" name="ref" value="{{ $transaction->reference }}">
            <input type="hidden" name="status" value="Paid">
            <button type="submit" class="btn btn-pay">✓ Complete Simulation (Mark as Paid)</button>
        </form>

        <form method="POST" action="{{ route('portal.paynow.mock-complete') }}">
            @csrf
            <input type="hidden" name="ref" value="{{ $transaction->reference }}">
            <input type="hidden" name="status" value="Cancelled">
            <button type="submit" class="btn btn-cancel">✕ Cancel Payment</button>
        </form>
    </div>
</div>
</body>
</html>

