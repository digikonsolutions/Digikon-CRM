@extends('layouts.app')
@section('title', $invoice->number)

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $invoice->number }} <span class="badge badge-{{ $invoice->is_overdue ? 'failed' : $invoice->status }}">{{ $invoice->is_overdue ? 'overdue' : $invoice->status }}</span>
            @if($invoice->source === 'auto')<span class="badge badge-auto">created automatically</span>@endif</h1>
        <small>{{ $invoice->client_name }} · issued {{ $invoice->issue_date->format('d M Y') }}{{ $invoice->sent_at ? ' · sent '.$invoice->sent_at->format('d M, h:i A') : '' }}{{ $invoice->paid_at ? ' · paid '.$invoice->paid_at->format('d M Y') : '' }}</small>
    </div>
    <div class="btn-group">
        <a href="{{ route('invoices.pdf', [$invoice, 'download' => 1]) }}" class="btn btn-outline">Download PDF</a>
        @if($invoice->lead)<a href="{{ route('leads.show', $invoice->lead) }}" class="btn btn-outline">Lead</a>@endif
        @if($invoice->proposal)<a href="{{ route('proposals.show', $invoice->proposal) }}" class="btn btn-outline">Proposal {{ $invoice->proposal->number }}</a>@endif
    </div>
</div>

<div class="grid grid-main">
    <div class="card" style="padding:0;overflow:hidden">
        <iframe src="{{ route('invoices.pdf', $invoice) }}" style="width:100%;height:80vh;border:0" title="Invoice PDF"></iframe>
    </div>
    <div>
        <div class="card">
            <h2>Amounts</h2>
            <div class="kv"><span>Project total</span><strong>{{ $invoice->money($invoice->total) }}</strong></div>
            <div class="kv"><span>Due now ({{ rtrim(rtrim(number_format($invoice->advance_percent, 2), '0'), '.') }}%)</span><strong>{{ $invoice->money($invoice->amount_due) }}</strong></div>
            @if($invoice->advance_percent < 100)<div class="kv"><span>Balance on delivery</span><span>{{ $invoice->money($invoice->balance_after) }}</span></div>@endif
            <div class="kv"><span>Due date</span><span class="{{ $invoice->is_overdue ? 'overdue' : '' }}">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</span></div>
        </div>
        <div class="card">
            <h2>Send</h2>
            <form method="POST" action="{{ route('invoices.send', $invoice) }}">
                @csrf
                <div class="form-group"><label for="inv-to">Email to</label><input id="inv-to" type="email" name="to" class="form-control" value="{{ $invoice->client_email }}"></div>
                <button class="btn">{{ $invoice->sent_at ? 'Send again' : 'Send invoice' }} (email + WhatsApp)</button>
            </form>
        </div>
        @can('invoices.manage')
        <div class="card">
            <h2>Status</h2>
            <div class="btn-group">
                @if($invoice->status !== 'paid')
                    <form method="POST" action="{{ route('invoices.status', $invoice) }}" class="inline-form" data-confirm="Mark {{ $invoice->number }} as PAID?">@csrf<input type="hidden" name="status" value="paid"><button class="btn btn-green">Mark paid</button></form>
                @else
                    <form method="POST" action="{{ route('invoices.status', $invoice) }}" class="inline-form">@csrf<input type="hidden" name="status" value="sent"><button class="btn btn-outline">Mark unpaid</button></form>
                @endif
                @if($invoice->status !== 'cancelled')
                    <form method="POST" action="{{ route('invoices.status', $invoice) }}" class="inline-form" data-confirm="Cancel this invoice?">@csrf<input type="hidden" name="status" value="cancelled"><button class="btn btn-red">Cancel</button></form>
                @endif
            </div>
        </div>
        @endcan
    </div>
</div>
@endsection
