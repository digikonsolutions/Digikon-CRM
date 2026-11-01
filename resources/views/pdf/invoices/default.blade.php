<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
    .header { background: {{ $brand['primary_color'] }}; padding: 30px 40px; }
    .header td { color: #ffffff; vertical-align: middle; }
    .company { font-size: 20px; font-weight: bold; color: #ffffff; }
    .doc-title { font-size: 28px; font-weight: bold; text-align: right; letter-spacing: 3px; color: {{ $brand['accent_color'] }}; }
    .accent-bar { height: 6px; background: {{ $brand['accent_color'] }}; }
    .content { padding: 28px 40px 90px; }
    .muted { color: #6b7280; }
    .label { font-size: 9.5px; letter-spacing: 1px; text-transform: uppercase; color: #6b7280; }
    .meta-box { background: #F4FBF2; padding: 14px 16px; border-left: 4px solid {{ $brand['accent_color'] }}; }
    .meta-box td { padding: 2px 0; }
    .items th { padding: 9px 8px; font-size: 10.5px; background: {{ $brand['primary_color'] }}; color: #ffffff; }
    .items td { padding: 9px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .items tbody tr:nth-child(even) td { background: #F6FAF8; }
    .totals { width: 48%; margin-top: 12px; }
    .totals td { padding: 6px 8px; }
    .due td { font-size: 14px; font-weight: bold; background: {{ $brand['accent_color'] }}; color: {{ $brand['primary_color'] }}; }
    .stamp { display: inline-block; padding: 6px 16px; border: 3px solid; border-radius: 6px; font-size: 18px; font-weight: bold; letter-spacing: 3px; }
    .pay { margin-top: 26px; background: #F6FAF8; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 16px; }
    h3 { color: {{ $brand['primary_color'] }}; font-size: 13px; margin: 0 0 6px; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; height: 46px; background: #F4FBF2; border-top: 3px solid {{ $brand['accent_color'] }}; color: {{ $brand['primary_color'] }}; font-size: 9.5px; padding: 10px 40px; }
</style>
</head>
<body>
<div class="footer">
    <table width="100%"><tr>
        <td style="color:{{ $brand['primary_color'] }};">{{ $brand['company_name'] }}{{ $brand['company_address'] ? ' · '.$brand['company_address'] : '' }}</td>
        <td style="color:{{ $brand['primary_color'] }}; text-align:right;">{{ $brand['company_phone'] }}{{ $brand['company_email'] ? ' · '.$brand['company_email'] : '' }}{{ $brand['company_website'] ? ' · '.$brand['company_website'] : '' }}</td>
    </tr></table>
</div>

<div class="header">
    <table width="100%"><tr>
        <td width="60%">
            @if($brand['logo'])
                <img src="{{ $brand['logo'] }}" style="max-height: 55px; max-width: 220px; background:#fff; padding:6px; border-radius:4px;">
            @else
                <div class="company">{{ $brand['company_name'] }}</div>
            @endif
        </td>
        <td width="40%" class="doc-title">INVOICE</td>
    </tr></table>
</div>
<div class="accent-bar"></div>

<div class="content">
    <table width="100%" cellspacing="0"><tr>
        <td width="52%" style="vertical-align: top;">
            <div class="label">Bill to</div>
            <div style="font-size: 15px; font-weight: bold; margin-top: 3px;">{{ $invoice->client_name }}</div>
            @if($invoice->client_company)<div>{{ $invoice->client_company }}</div>@endif
            @if($invoice->client_email)<div class="muted">{{ $invoice->client_email }}</div>@endif
            @if($invoice->client_phone)<div class="muted">+{{ ltrim($invoice->client_phone, '+') }}</div>@endif
            @if($invoice->status === 'paid')
                <div style="margin-top:16px;"><span class="stamp" style="color:#2F7A23; border-color:#2F7A23;">PAID</span></div>
            @endif
        </td>
        <td width="48%" style="vertical-align: top;">
            <div class="meta-box">
                <table width="100%">
                    <tr><td class="muted">Invoice #</td><td style="text-align:right;"><strong>{{ $invoice->number }}</strong></td></tr>
                    <tr><td class="muted">Issue date</td><td style="text-align:right;">{{ $invoice->issue_date->format('d M Y') }}</td></tr>
                    @if($invoice->due_date)<tr><td class="muted">Due date</td><td style="text-align:right;"><strong>{{ $invoice->due_date->format('d M Y') }}</strong></td></tr>@endif
                    @if($invoice->proposal)<tr><td class="muted">Proposal ref.</td><td style="text-align:right;">{{ $invoice->proposal->number }}</td></tr>@endif
                </table>
            </div>
        </td>
    </tr></table>

    <table class="items" width="100%" cellspacing="0" cellpadding="0" style="margin-top: 26px;">
        <thead><tr>
            <th style="width: 6%;">#</th><th style="text-align:left;">Description</th><th style="width: 10%;">Qty</th>
            <th style="width: 18%; text-align:right;">Price</th><th style="width: 20%; text-align:right;">Amount</th>
        </tr></thead>
        <tbody>
        @foreach($invoice->items ?? [] as $i => $item)
            <tr>
                <td style="text-align:center;">{{ $i + 1 }}</td>
                <td>{!! nl2br(e($item['description'])) !!}</td>
                <td style="text-align:center;">{{ rtrim(rtrim(number_format($item['qty'], 2), '0'), '.') }}</td>
                <td style="text-align:right;">{{ number_format($item['price'], 2) }}</td>
                <td style="text-align:right;">{{ number_format($item['qty'] * $item['price'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" cellspacing="0" cellpadding="0" align="right">
        <tr><td>Subtotal</td><td style="text-align:right;">{{ $invoice->money($invoice->subtotal) }}</td></tr>
        @if($invoice->discount > 0)<tr><td>Discount</td><td style="text-align:right;">- {{ $invoice->money($invoice->discount) }}</td></tr>@endif
        @if($invoice->tax_percent > 0)<tr><td>Tax ({{ rtrim(rtrim(number_format($invoice->tax_percent, 2), '0'), '.') }}%)</td><td style="text-align:right;">{{ $invoice->money($invoice->tax_amount) }}</td></tr>@endif
        <tr><td style="border-top:1px solid #9ca3af;"><strong>Project total</strong></td><td style="text-align:right; border-top:1px solid #9ca3af;"><strong>{{ $invoice->money($invoice->total) }}</strong></td></tr>
        @if($invoice->advance_percent < 100)
            <tr class="due"><td>Due now ({{ rtrim(rtrim(number_format($invoice->advance_percent, 2), '0'), '.') }}% advance)</td><td style="text-align:right;">{{ $invoice->money($invoice->amount_due) }}</td></tr>
            <tr><td class="muted">Balance on delivery</td><td style="text-align:right;" class="muted">{{ $invoice->money($invoice->balance_after) }}</td></tr>
        @else
            <tr class="due"><td>Amount due</td><td style="text-align:right;">{{ $invoice->money($invoice->amount_due) }}</td></tr>
        @endif
    </table>
    <div style="clear:both;"></div>

    @if($invoice->payment_instructions)
        <div class="pay">
            <h3>Payment details</h3>
            <div style="line-height:1.6;">{!! nl2br(e($invoice->payment_instructions)) !!}</div>
        </div>
    @endif
    @if($invoice->notes)
        <div style="margin-top:18px;"><h3>Notes</h3><div style="line-height:1.6;">{!! nl2br(e($invoice->notes)) !!}</div></div>
    @endif

    <p style="margin-top:30px;" class="muted">Thank you for your business! Please mention <strong>{{ $invoice->number }}</strong> with your payment.</p>
</div>
</body>
</html>
