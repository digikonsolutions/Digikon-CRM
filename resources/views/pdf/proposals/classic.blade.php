<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 45px 55px 70px; }
    body { font-family: 'DejaVu Serif', serif; font-size: 11px; color: #222; }
    .top { border-bottom: 3px double {{ $brand['primary_color'] }}; padding-bottom: 14px; }
    .company { font-size: 22px; color: {{ $brand['primary_color'] }}; font-weight: bold; }
    .addr { font-size: 9.5px; color: #555; line-height: 1.5; }
    .title-block { text-align: center; margin: 26px 0 18px; }
    .title-block .label { font-size: 10px; letter-spacing: 4px; color: {{ $brand['primary_color'] }}; }
    .title-block .rule { width: 60px; height: 3px; background: {{ $brand['accent_color'] }}; margin: 8px auto 0; }
    .title-block .title { font-size: 20px; font-weight: bold; margin-top: 4px; }
    h3 { font-size: 13px; color: {{ $brand['primary_color'] }}; text-transform: uppercase; letter-spacing: 1px; margin: 22px 0 8px; }
    .items th { padding: 8px; border-top: 1px solid {{ $brand['primary_color'] }}; border-bottom: 1px solid {{ $brand['primary_color'] }}; font-size: 10.5px; }
    .items td { padding: 8px; border-bottom: 1px dotted #bbb; vertical-align: top; }
    .totals { width: 45%; margin-top: 12px; }
    .totals td { padding: 5px 8px; }
    .totals .grand td { font-weight: bold; font-size: 13px; }
    .footer { position: fixed; bottom: -45px; left: 0; right: 0; text-align: center; font-size: 9px; color: #777; border-top: 1px solid #ccc; padding-top: 6px; }
</style>
</head>
<body>
<div class="footer">{{ $brand['footer_text'] }} — {{ $brand['company_name'] }}</div>

<table width="100%" class="top" cellspacing="0"><tr>
    <td width="55%" style="vertical-align: middle;">
        @if($brand['logo'])
            <img src="{{ $brand['logo'] }}" style="max-height: 60px; max-width: 230px;">
        @else
            <div class="company">{{ $brand['company_name'] }}</div>
        @endif
    </td>
    <td width="45%" class="addr" style="text-align: right; vertical-align: middle;">
        <strong>{{ $brand['company_name'] }}</strong><br>
        @if($brand['company_address']){!! nl2br(e($brand['company_address'])) !!}<br>@endif
        {{ $brand['company_phone'] }}@if($brand['company_email']) · {{ $brand['company_email'] }}@endif
        @if($brand['company_website'])<br>{{ $brand['company_website'] }}@endif
    </td>
</tr></table>

<div class="title-block">
    <div class="label">BUSINESS PROPOSAL</div>
    <div class="title">{{ $proposal->title }}</div>
    <div class="rule"></div>
</div>

<table width="100%" cellspacing="0"><tr>
    <td width="50%" style="vertical-align: top;">
        <strong>To:</strong><br>
        {{ $proposal->client_name }}<br>
        @if($proposal->client_company){{ $proposal->client_company }}<br>@endif
        @if($proposal->client_email){{ $proposal->client_email }}@endif
    </td>
    <td width="50%" style="text-align: right; vertical-align: top;">
        <strong>Ref:</strong> {{ $proposal->number }}<br>
        <strong>Date:</strong> {{ ($proposal->created_at ?? now())->format('d F Y') }}<br>
        @if($proposal->valid_until)<strong>Valid until:</strong> {{ $proposal->valid_until->format('d F Y') }}@endif
    </td>
</tr></table>

@if($proposal->introduction)
    <p style="margin-top: 20px;">Dear {{ $proposal->client_name }},</p>
    <p style="line-height: 1.7; text-align: justify;">{!! nl2br(e($proposal->introduction)) !!}</p>
@endif

<h3>Proposed Services & Fees</h3>
@include('pdf.proposals._items', ['headBg' => '#ffffff', 'headColor' => $brand['primary_color']])

@if($proposal->terms)
    <h3>Terms & Conditions</h3>
    <p style="line-height: 1.7;">{!! nl2br(e($proposal->terms)) !!}</p>
@endif

<p style="margin-top: 20px;">Sincerely,</p>
@include('pdf.proposals._signature')
</body>
</html>
