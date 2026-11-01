@extends('layouts.app')
@section('title', 'Invoices')

@section('content')
<div class="page-head">
    <div><h1>Invoices <small>({{ $invoices->total() }})</small></h1>
        <small>Created automatically when a proposal is accepted (Settings → Sales Automation), or from a proposal page.</small></div>
    <div class="seg">
        @foreach(['' => 'All', 'sent' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'draft' => 'Draft', 'cancelled' => 'Cancelled'] as $k => $v)
            <a href="{{ route('invoices.index', $k ? ['status' => $k] : []) }}" class="{{ request('status', '') === $k ? 'active' : '' }}">{{ $v }}</a>
        @endforeach
    </div>
</div>

<div class="grid grid-2">
    <div class="stat warn"><div class="num" style="font-size:26px">@forelse($outstanding as $c => $t){{ $c }} {{ number_format($t) }}@if(! $loop->last)<br>@endif @empty 0 @endforelse</div><div class="label">Outstanding (sent, not paid)</div></div>
    <div class="stat green"><div class="num" style="font-size:26px">@forelse($paidThisMonth as $c => $t){{ $c }} {{ number_format($t) }}@if(! $loop->last)<br>@endif @empty 0 @endforelse</div><div class="label">Received this month</div></div>
</div>

<div class="card mt">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Invoice</th><th>Client</th><th>Proposal</th><th class="text-right">Total</th><th class="text-right">Due now</th><th>Due date</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($invoices as $inv)
                <tr>
                    <td><a href="{{ route('invoices.show', $inv) }}"><strong>{{ $inv->number }}</strong></a> @if($inv->source === 'auto')<span class="badge badge-auto">auto</span>@endif<br><small>{{ $inv->issue_date->format('d M Y') }}</small></td>
                    <td>{{ $inv->client_name }}@if($inv->lead)<br><small><a href="{{ route('leads.show', $inv->lead) }}">lead</a></small>@endif</td>
                    <td>@if($inv->proposal)<a href="{{ route('proposals.show', $inv->proposal_id) }}">{{ $inv->proposal->number }}</a>@else — @endif</td>
                    <td class="text-right">{{ $inv->money($inv->total) }}</td>
                    <td class="text-right"><strong>{{ $inv->money($inv->amount_due) }}</strong><br><small>{{ rtrim(rtrim(number_format($inv->advance_percent, 2), '0'), '.') }}%</small></td>
                    <td class="{{ $inv->is_overdue ? 'overdue' : '' }}">{{ $inv->due_date?->format('d M Y') ?? '—' }}</td>
                    <td><span class="badge badge-{{ $inv->is_overdue ? 'failed' : $inv->status }}">{{ $inv->is_overdue ? 'overdue' : $inv->status }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No invoices yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}
</div>
@endsection
