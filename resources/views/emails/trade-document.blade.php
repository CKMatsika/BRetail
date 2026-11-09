@php
    $kindTitle = ucwords(str_replace('_', ' ', $document->kind));
    $currency = $company->base_currency;
    $due = $presented['due_on'] ?? $presented['expected_on'] ?? null;
    $dateLabel = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '—';
    $money = fn ($v) => number_format((float) ($v ?? 0), 2, '.', ',');
    $companyDetails = $company->settings ?? [];
    $bankingDetails = trim($companyDetails['banking_details'] ?? '');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $customSubject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1e293b;line-height:1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:32px 16px;">
<tr>
<td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06);border:1px solid #e2e8f0;">
    <!-- Brand Header -->
    <tr>
        <td style="background-color:#0f172a;padding:28px 32px;color:#ffffff;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="vertical-align:middle;">
                        <h1 style="margin:0;font-size:20px;font-weight:700;color:#ffffff;letter-spacing:-0.02em;">{{ $company->name }}</h1>
                        <p style="margin:4px 0 0 0;font-size:12px;color:#94a3b8;">{{ $kindTitle }} · {{ $presented['number'] }}</p>
                    </td>
                    <td align="right" style="vertical-align:middle;">
                        <span style="display:inline-block;padding:6px 14px;background-color:#1e293b;border-radius:20px;font-size:12px;font-weight:600;color:#38bdf8;text-transform:uppercase;letter-spacing:0.05em;">
                            {{ $kindTitle }}
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Body Content -->
    <tr>
        <td style="padding:32px;">
            <p style="margin:0 0 16px 0;font-size:15px;color:#334155;">
                Dear <strong>{{ $presented['party_name'] ?? 'Valued Customer' }}</strong>,
            </p>

            @if(!empty($customMessage))
            <div style="background-color:#f8fafc;border-left:4px solid #3b82f6;padding:14px 16px;margin:0 0 24px 0;border-radius:0 8px 8px 0;font-size:14px;color:#334155;white-space:pre-line;">{{ $customMessage }}</div>
            @else
            <p style="margin:0 0 24px 0;font-size:14px;color:#475569;">
                Please find details of your <strong>{{ strtolower($kindTitle) }}</strong> below. You can view, print, or download the official copy using the secure link.
            </p>
            @endif

            <!-- Key Details Card -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;margin:0 0 28px 0;">
                <tr>
                    <td style="padding:16px 20px;border-bottom:1px solid #e2e8f0;width:50%;">
                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;">Document Number</span>
                        <strong style="font-size:15px;color:#0f172a;display:block;margin-top:2px;">{{ $presented['number'] }}</strong>
                    </td>
                    <td style="padding:16px 20px;border-bottom:1px solid #e2e8f0;width:50%;">
                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;">Issue Date</span>
                        <strong style="font-size:15px;color:#0f172a;display:block;margin-top:2px;">{{ $dateLabel($presented['date']) }}</strong>
                    </td>
                </tr>
                <tr>
                    @if($due)
                    <td style="padding:16px 20px;width:50%;">
                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;">{{ str_contains(strtolower($kindTitle), 'quote') ? 'Valid Until' : 'Due Date' }}</span>
                        <strong style="font-size:15px;color:#0f172a;display:block;margin-top:2px;">{{ $dateLabel($due) }}</strong>
                    </td>
                    @endif
                    <td style="padding:16px 20px;width:50%;" {{ !$due ? 'colspan=2' : '' }}>
                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;">Total Amount</span>
                        <strong style="font-size:20px;color:#047857;display:block;margin-top:2px;">{{ $currency }} {{ $money($presented['total'] ?? 0) }}</strong>
                    </td>
                </tr>
            </table>

            <!-- Call to Action Button -->
            <div style="text-align:center;margin:32px 0;">
                <div style="margin-bottom:12px;">
                    <a href="{{ $portalUrl }}" target="_blank" style="display:inline-block;padding:14px 28px;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;box-shadow:0 4px 12px rgba(37,99,235,0.25);margin:4px;">
                        View & Print Document Online →
                    </a>
                    @if(in_array(strtolower($document->kind), ['invoice', 'quote']))
                    <a href="{{ $portalUrl }}#paynow" target="_blank" style="display:inline-block;padding:14px 28px;background-color:#008751;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;border-radius:8px;box-shadow:0 4px 12px rgba(0,135,81,0.25);margin:4px;">
                        ⚡ Pay Online (EcoCash / Card)
                    </a>
                    @endif
                </div>
                <p style="margin:6px 0 0 0;font-size:11px;color:#94a3b8;">
                    No login required. Secure payment powered by Paynow Zimbabwe.
                </p>
            </div>

            <!-- Banking Details (if invoice or quotation) -->
            @if($bankingDetails && in_array(strtolower($document->kind), ['invoice', 'quote']))
            <div style="margin-top:28px;padding:16px;background-color:#fefce8;border:1px solid #fef08a;border-radius:8px;">
                <strong style="font-size:12px;color:#854d0e;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:6px;">Banking Details for Settlement</strong>
                <p style="margin:0;font-size:12px;line-height:1.6;color:#713f12;white-space:pre-line;">{{ $bankingDetails }}</p>
            </div>
            @endif
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="background-color:#f8fafc;padding:24px 32px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td>
                        <strong style="color:#334155;">{{ $company->name }}</strong>
                        @if(!empty($companyDetails['phone']))<br>Tel: {{ $companyDetails['phone'] }}@endif
                        @if(!empty($companyDetails['contact_email']))<br>Email: {{ $companyDetails['contact_email'] }}@endif
                    </td>
                    <td align="right" style="vertical-align:bottom;font-size:11px;color:#94a3b8;">
                        Powered by BRetail
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</td>
</tr>
</table>
</body>
</html>

