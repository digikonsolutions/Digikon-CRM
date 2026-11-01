@extends('layouts.app')
@section('title', 'Audit Logs')

@section('content')
<div class="page-head"><h1>Audit Logs</h1><span class="muted">Assignments, status changes, deletions, settings, permissions, exports…</span></div>

<div class="card">
    <form method="GET" class="filters">
        <div>
            <label for="au-user">User</label>
            <select id="au-user" name="user_id" class="form-control"><option value="">All</option>@foreach($users as $usr)<option value="{{ $usr->id }}" @selected(request('user_id') == $usr->id)>{{ $usr->name }}</option>@endforeach</select>
        </div>
        <div><label for="au-action">Action starts with</label><input id="au-action" type="text" name="action" class="form-control" value="{{ request('action') }}" placeholder="lead., meeting., settings."></div>
        <div>
            <label for="au-type">Record type</label>
            <select id="au-type" name="type" class="form-control"><option value="">All</option>@foreach($types as $t)<option value="{{ $t }}" @selected(request('type') === $t)>{{ $t }}</option>@endforeach</select>
        </div>
        <div><label for="au-id">Record ID</label><input id="au-id" type="number" name="id" class="form-control" value="{{ request('id') }}" style="width:110px"></div>
        <div><label for="au-from">From</label><input id="au-from" type="date" name="date_from" class="form-control" value="{{ request('date_from') }}"></div>
        <div><label for="au-to">To</label><input id="au-to" type="date" name="date_to" class="form-control" value="{{ request('date_to') }}"></div>
        <div><button class="btn">Filter</button> <a href="{{ route('audit.index') }}" class="btn btn-outline">Reset</a></div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Before</th><th>After</th><th>Note</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td><small>{{ $log->created_at->format('d M Y') }}<br>{{ $log->created_at->format('h:i:s A') }}</small></td>
                    <td>{{ $log->user?->name ?? 'System' }}<br><small>{{ $log->ip }}</small></td>
                    <td><span class="badge">{{ $log->action }}</span></td>
                    <td>
                        @if($log->auditable_type === 'Lead' && $log->auditable_id)<a href="{{ route('leads.show', $log->auditable_id) }}">Lead #{{ $log->auditable_id }}</a>
                        @elseif($log->auditable_type === 'Meeting' && $log->auditable_id)<a href="{{ route('meetings.show', $log->auditable_id) }}">Meeting #{{ $log->auditable_id }}</a>
                        @elseif($log->auditable_type){{ $log->auditable_type }} #{{ $log->auditable_id }}@else — @endif
                    </td>
                    <td>@if($log->old_values)<span class="json">{{ json_encode($log->old_values, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</span>@endif</td>
                    <td>@if($log->new_values)<span class="json">{{ json_encode($log->new_values, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</span>@endif</td>
                    <td><small>{{ $log->description }}</small></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No audit entries.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links() }}
</div>
@endsection
