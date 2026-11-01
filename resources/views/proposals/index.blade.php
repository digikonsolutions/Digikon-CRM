@extends('layouts.app')
@section('title', 'Proposals')

@section('content')
<div class="page-head">
    <h1>Proposals</h1>
    <div class="btn-group">
        <form method="GET" class="flex">
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach(['draft', 'sent', 'accepted', 'rejected'] as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('proposals.create') }}" class="btn">+ New Proposal</a>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Number</th><th>Title</th><th>Client</th><th>Template</th><th class="text-right">Total</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
            @forelse($proposals as $p)
                <tr>
                    <td><a href="{{ route('proposals.show', $p) }}"><strong>{{ $p->number }}</strong></a></td>
                    <td>{{ $p->title }}</td>
                    <td>{{ $p->client_name }}<br><small>{{ $p->client_email }}</small>
                        @if($p->lead)<br><small><a href="{{ route('leads.show', $p->lead) }}">View lead</a></small>@endif</td>
                    <td>{{ ucfirst($p->template) }}</td>
                    <td class="text-right">{{ $p->money($p->total) }}</td>
                    <td><span class="badge badge-{{ $p->status }}">{{ $p->status }}</span>
                        @if($p->sent_at)<br><small>{{ $p->sent_at->format('d M Y') }}</small>@endif</td>
                    <td><small>{{ $p->created_at->format('d M Y') }}<br>{{ $p->user?->name }}</small></td>
                    <td class="text-right"><a href="{{ route('proposals.pdf', $p) }}" target="_blank" class="btn btn-outline btn-sm">PDF</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No proposals yet. Open a lead and click "Create Proposal".</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $proposals->links() }}
</div>
@endsection
