<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
    .header { background: #F4FBF2; color: {{ $brand['primary_color'] }}; padding: 30px 40px; }
    .header table td { color: {{ $brand['primary_color'] }}; vertical-align: middle; }
    .company { font-size: 20px; font-weight: bold; }
    .doc-title { font-size: 26px; font-weight: bold; text-align: right; letter-spacing: 2px; color: {{ $brand['primary_color'] }} !important; }
    .accent-bar { height: 6px; background: {{ $brand['accent_color'] }}; }
    .content { padding: 28px 40px 90px; }
    .meta-box { background: #F4FBF2; padding: 14px 16px; border-left: 4px solid {{ $brand['accent_color'] }}; }
    h2 { color: {{ $brand['primary_color'] }}; font-size: 15px; margin: 24px 0 8px; border-bottom: 2px solid {{ $brand['accent_color'] }}; padding-bottom: 4px; }
    .items { margin-top: 6px; }
    .items th { padding: 9px 8px; font-size: 10.5px; }
    .items td { padding: 9px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .items tbody tr:nth-child(even) td { background: #F6FAF8; }
    .totals { width: 45%; margin-top: 12px; }
    .totals td { padding: 6px 8px; }
    .totals .grand td { font-size: 13px; font-weight: bold; }
    .footer { position: fixed; bottom: 0; left: 0; right: 0; height: 46px; background: #F4FBF2; border-top: 3px solid {{ $brand['accent_color'] }}; color: {{ $brand['primary_color'] }}; font-size: 9.5px; padding: 10px 40px; }
    .muted { color: #6b7280; }
</style>
</head>
<body>
<div class="footer">
    <table width="100%"><tr>
        <td style="color:{{ $brand['primary_color'] }};">{{ $brand['footer_text'] }}</td>
        <td style="color:{{ $brand['primary_color'] }}; text-align:right;">{{ $brand['company_phone'] }} {{ $brand['company_email'] ? ' · '.$brand['company_email'] : '' }} {{ $brand['company_website'] ? ' · '.$brand['company_website'] : '' }}</td>
    </tr></table>
</div>

<div class="header">
    <table width="100%"><tr>
        <td width="60%">
            @if($brand['logo'])
                <img src="{{ $brand['logo'] }}" style="max-height: 55px; max-width: 220px; ">
            @else
                <div class="company">{{ $brand['company_name'] }}</div>
            @endif
        </td>
        <td width="40%" class="doc-title">PROPOSAL</td>
    </tr></table>
</div>
<div class="accent-bar"></div>

<div class="content">
    <table width="100%" cellspacing="0"><tr>
        <td width="55%" style="vertical-align: top;">
            <div class="muted">PREPARED FOR</div>
            <div style="font-size: 15px; font-weight: bold; margin-top: 3px;">{{ $proposal->client_name }}</div>
            @if($proposal->client_company)<div>{{ $proposal->client_company }}</div>@endif
            @if($proposal->client_email)<div class="muted">{{ $proposal->client_email }}</div>@endif
        </td>
        <td width="45%" style="vertical-align: top;">
            <div class="meta-box">
                <table width="100%">
                    <tr><td class="muted">Proposal #</td><td style="text-align:right;"><strong>{{ $proposal->number }}</strong></td></tr>
                    <tr><td class="muted">Date</td><td style="text-align:right;">{{ ($proposal->created_at ?? now())->format('d M Y') }}</td></tr>
                    @if($proposal->valid_until)<tr><td class="muted">Valid until</td><td style="text-align:right;">{{ $proposal->valid_until->format('d M Y') }}</td></tr>@endif
                </table>
            </div>
        </td>
    </tr></table>

    <h2 style="font-size: 18px; border: 0; margin-top: 26px;">{{ $proposal->title }}</h2>
    @if($proposal->introduction)<p style="line-height: 1.6;">{!! nl2br(e($proposal->introduction)) !!}</p>@endif

    <h2>Scope & Investment</h2>
    @include('pdf.proposals._items', ['headBg' => $brand['accent_color'], 'headColor' => $brand['primary_color']])

    @if($proposal->terms)
        <h2>Terms & Conditions</h2>
        <p style="line-height: 1.6;">{!! nl2br(e($proposal->terms)) !!}</p>
    @endif

    @include('pdf.proposals._signature')
</div>
</body>
</html>
