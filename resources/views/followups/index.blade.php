@extends('layouts.app')
@section('title', 'WhatsApp Follow-ups')

@section('content')
<div class="page-head">
    <h1>Auto Follow-ups <small>(WhatsApp &amp; Email)</small></h1>
    <div class="btn-group">
        <form method="POST" action="{{ route('followups.toggle') }}" class="inline-form">
            @csrf
            <button class="btn {{ $autoFollowups ? 'btn-red' : 'btn-green' }}">{{ $autoFollowups ? 'Turn auto follow-ups OFF' : 'Turn auto follow-ups ON' }}</button>
        </form>
        <a href="{{ route('followups.create', ['channel' => 'whatsapp']) }}" class="btn">+ WhatsApp step</a>
        <a href="{{ route('followups.create', ['channel' => 'email']) }}" class="btn btn-outline">+ Email step</a>
    </div>
</div>

<div class="alert alert-info">
    These steps are scheduled automatically for every new lead: WhatsApp steps when the lead has a phone number, Email steps when the lead has an email address. Remaining follow-ups stop on their own when the customer replies, sends STOP, or the lead becomes Qualified, Won or Lost.
    <br><strong>Note:</strong> The first message must always be a Meta-approved <em>template</em> — free text is only delivered within the 24-hour customer service window.
    Status: <strong>{{ $autoFollowups ? 'ON' : 'OFF' }}</strong>
</div>

<div class="card">
    <h2>Sequence Steps</h2>
    <table class="table">
        <thead><tr><th>#</th><th>Step</th><th>When</th><th>Channel</th><th>Template / Message</th><th>Active</th><th></th></tr></thead>
        <tbody>
        @forelse($steps as $step)
            <tr>
                <td>{{ $step->sort_order }}</td>
                <td><strong>{{ $step->name }}</strong></td>
                <td>{{ $step->delay_label }}</td>
                <td><span class="badge {{ $step->channel === 'email' ? 'badge-meta' : 'badge-whatsapp' }}">{{ \App\Models\FollowupStep::CHANNELS[$step->channel] ?? $step->channel }}</span>@if($step->channel !== 'email')<br><small>{{ $step->message_type }}</small>@endif</td>
                <td>
                    @if($step->channel === 'email')
                        <strong>{{ $step->email_subject }}</strong><br><small>{{ \Illuminate\Support\Str::limit($step->message, 120) }}</small>
                    @elseif($step->message_type === 'template')
                        <code>{{ $step->template_name }}</code> ({{ $step->template_language }})<br><small>Variables: {{ $step->message ?: '—' }}</small>
                    @else
                        <small>{{ \Illuminate\Support\Str::limit($step->message, 120) }}</small>
                    @endif
                </td>
                <td>{!! $step->is_active ? '<span class="badge badge-sent">Yes</span>' : '<span class="badge badge-cancelled">No</span>' !!}</td>
                <td class="text-right">
                    <a href="{{ route('followups.edit', $step) }}" class="btn btn-outline btn-sm">Edit</a>
                    <form method="POST" action="{{ route('followups.destroy', $step) }}" class="inline-form" onsubmit="return confirm('Delete?')">
                        @csrf @method('DELETE') <button class="btn btn-red btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">No steps yet. Click "Add Step" to create one.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Follow-up Queue (latest 50)</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Lead</th><th>Step</th><th>Scheduled</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($queue as $f)
                <tr>
                    <td>@if($f->lead)<a href="{{ route('leads.show', $f->lead) }}">{{ $f->lead->display_name }}</a>@else — @endif</td>
                    <td>{{ $f->step?->name }}</td>
                    <td>{{ $f->scheduled_at->format('d M, h:i A') }} <small>({{ $f->scheduled_at->diffForHumans() }})</small></td>
                    <td><span class="badge badge-{{ $f->status }}">{{ $f->status }}</span>@if($f->error)<br><small>{{ $f->error }}</small>@endif</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">The queue is empty.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
