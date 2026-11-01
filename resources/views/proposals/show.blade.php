@extends('layouts.app')
@section('title', $proposal->number)

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $proposal->number }} <span class="badge badge-{{ $proposal->status }}">{{ $proposal->status }}</span>
            @if($proposal->source === 'auto')<span class="badge badge-auto">sent automatically</span>@endif</h1>
        <small>{{ $proposal->title }} · {{ $proposal->client_name }} · {{ $proposal->money($proposal->total) }}</small>
    </div>
    <div class="btn-group">
        <a href="{{ route('proposals.pdf', $proposal) }}" target="_blank" class="btn btn-outline">Open PDF</a>
        <a href="{{ route('proposals.pdf', ['proposal' => $proposal, 'download' => 1]) }}" class="btn btn-outline">Download PDF</a>
        <a href="{{ route('proposals.edit', $proposal) }}" class="btn btn-outline">Edit</a>
        <form method="POST" action="{{ route('proposals.destroy', $proposal) }}" class="inline-form" onsubmit="return confirm('Delete this proposal?')">
            @csrf @method('DELETE') <button class="btn btn-red">Delete</button>
        </form>
    </div>
</div>

<div class="grid grid-main">
    <div class="card" style="padding:0; overflow:hidden">
        <iframe src="{{ route('proposals.pdf', $proposal) }}" style="width:100%; height:820px; border:0"></iframe>
    </div>

    <div>
        <div class="card">
            <h2>Email to client (PDF attached)</h2>
            <form method="POST" action="{{ route('proposals.send', $proposal) }}">
                @csrf
                <div class="form-group"><label>To *</label><input type="email" name="to" class="form-control" value="{{ old('to', $proposal->client_email ?: $proposal->lead?->email) }}" required></div>
                <div class="form-group"><label>CC</label><input type="email" name="cc" class="form-control" value="{{ old('cc') }}"></div>
                <div class="form-group"><label>Subject *</label><input type="text" name="subject" class="form-control" value="{{ old('subject', $subject) }}" required></div>
                <div class="form-group"><label>Message *</label><textarea name="body" class="form-control" rows="10" required>{{ old('body', $body) }}</textarea></div>
                <button class="btn btn-green" style="width:100%">Send Proposal Email</button>
                @if($proposal->sent_at)<p class="help mt">Last sent: {{ $proposal->sent_at->format('d M Y, h:i A') }}</p>@endif
            </form>
        </div>

        <div class="card">
            <h2>Proposal status</h2>
            <form method="POST" action="{{ route('proposals.status', $proposal) }}" class="flex">
                @csrf
                <select name="status" class="form-control">
                    @foreach(['draft', 'sent', 'accepted', 'rejected'] as $st)
                        <option value="{{ $st }}" @selected($proposal->status === $st)>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm">Update</button>
            </form>
            <p class="help mt">Marking as "Accepted" sets the lead to Won{{ \App\Models\Setting::get('auto_invoice_trigger', 'on_accept') === 'on_accept' ? ' and automatically creates + sends the invoice PDF' : '' }}.</p>
            @php($invs = $proposal->invoices()->latest()->get())
            @if($invs->count())
                <h3 class="mt">Invoices</h3>
                @foreach($invs as $inv)<p><a href="{{ route('invoices.show', $inv) }}">{{ $inv->number }}</a> — {{ $inv->money($inv->amount_due) }} <span class="badge badge-{{ $inv->status }}">{{ $inv->status }}</span></p>@endforeach
            @elseif(auth()->user()->hasPermission('invoices.manage'))
                <form method="POST" action="{{ route('proposals.invoice', $proposal) }}" class="mt" data-confirm="Create and send the invoice for {{ $proposal->number }}?">@csrf<button class="btn btn-outline btn-sm">Create &amp; send invoice</button></form>
            @endif
            @if($proposal->lead)
                <p class="mt">Lead: <a href="{{ route('leads.show', $proposal->lead) }}">{{ $proposal->lead->display_name }}</a></p>
            @endif
        </div>
    </div>
</div>
@endsection
