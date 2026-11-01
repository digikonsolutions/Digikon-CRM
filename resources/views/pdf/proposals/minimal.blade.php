<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 50px 55px 60px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #333; }
    .dot { display: inline-block; width: 10px; height: 10px; background: {{ $brand['accent_color'] }}; border-radius: 5px; }
    .big { font-size: 30px; font-weight: bold; color: #111; margin: 30px 0 4px; }
    .sub { color: #888; font-size: 11px; }
    .label { font-size: 9px; color: #999; letter-spacing: 1.5px; text-transform: uppercase; }
    .section { margin-top: 26px; }
    .section-title { font-size: 9px; color: {{ $brand['primary_color'] }}; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; margin-bottom: 8px; border-left: 3px solid {{ $brand['accent_color'] }}; padding-left: 7px; }
    .items th { padding: 8px 6px; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; border-bottom: 2px solid #111; }
    .items td { padding: 10px 6px; border-bottom: 1px solid #eee; vertical-align: top; }
    .totals { width: 42%; margin-top: 12px; }
    .totals td { padding: 5px 6px; }
    .totals .grand td { font-weight: bold; font-size: 13px; padding: 8px 6px; }
    .footer { position: fixed; bottom: -35px; left: 0; right: 0; font-size: 8.5px; color: #aaa; }
</style>
</head>
<body>
<div class="footer">
    <table width="100%"><tr>
        <td>{{ $brand['company_name'] }} · {{ $proposal->number }}</td>
        <td style="text-align:right;">{{ $brand['footer_text'] }}</td>
    </tr></table>
</div>

<table width="100%" cellspacing="0"><tr>
    <td style="vertical-align: middle;">
        @if($brand['logo'])
            <img src="{{ $brand['logo'] }}" style="max-height: 42px; max-width: 180px;">
        @else
            <span class="dot"></span> <strong style="font-size: 14px;">{{ $brand['company_name'] }}</strong>
        @endif
    </td>
    <td style="text-align: right; color: #888; font-size: 9.5px; vertical-align: middle;">
        {{ $brand['company_email'] }}<br>{{ $brand['company_phone'] }}
    </td>
</tr></table>

<div class="big">{{ $proposal->title }}</div>
<div class="sub">Proposal {{ $proposal->number }}</div>

<table width="100%" cellspacing="0" style="margin-top: 26px;"><tr>
    <td width="40%" style="vertical-align: top;">
        <div class="label">Client</div>
        <strong>{{ $proposal->client_name }}</strong><br>
        @if($proposal->client_company){{ $proposal->client_company }}<br>@endif
        <span style="color:#888;">{{ $proposal->client_email }}</span>
    </td>
    <td width="30%" style="vertical-align: top;">
        <div class="label">Issued</div>
        {{ ($proposal->created_at ?? now())->format('d M Y') }}
    </td>
    <td width="30%" style="vertical-align: top;">
        <div class="label">Valid until</div>
        {{ $proposal->valid_until?->format('d M Y') ?? '—' }}
    </td>
</tr></table>

@if($proposal->introduction)
<div class="section">
    <div class="section-title">Overview</div>
    <p style="line-height: 1.7;">{!! nl2br(e($proposal->introduction)) !!}</p>
</div>
@endif

<div class="section">
    <div class="section-title">Investment</div>
    @include('pdf.proposals._items', ['headBg' => '#ffffff', 'headColor' => '#111111'])
</div>

@if($proposal->terms)
<div class="section">
    <div class="section-title">Terms</div>
    <p style="line-height: 1.7; color: #555;">{!! nl2br(e($proposal->terms)) !!}</p>
</div>
@endif

@include('pdf.proposals._signature')
</body>
</html>
