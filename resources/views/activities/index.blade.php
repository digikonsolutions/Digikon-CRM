@extends('layouts.app')
@section('title', 'Activities')

@section('content')
<div class="page-head">
    <h1>Sales Activities <small>({{ $activities->total() }})</small></h1>
    <span class="muted">{{ $team ? 'Whole team' : 'Your activities' }} · calls, WhatsApp, email, meetings, notes</span>
</div>

<div class="card">
    <form method="GET" class="filters">
        @if($team)
        <div>
            <label for="a-agent">Agent</label>
            <select id="a-agent" name="agent" class="form-control">
                <option value="">All</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('agent') == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        </div>
        @endif
        <div>
            <label for="a-type">Type</label>
            <select id="a-type" name="type" class="form-control">
                <option value="">All</option>
                @foreach(config('crm.activity_types') as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="a-outcome">Call outcome</label>
            <select id="a-outcome" name="outcome" class="form-control">
                <option value="">All</option>
                @foreach(config('crm.call_outcomes') as $k => $v)<option value="{{ $k }}" @selected(request('outcome') === $k)>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div><label for="a-from">From</label><input id="a-from" type="date" name="date_from" class="form-control" value="{{ request('date_from') }}"></div>
        <div><label for="a-to">To</label><input id="a-to" type="date" name="date_to" class="form-control" value="{{ request('date_to') }}"></div>
        <div><button class="btn">Filter</button> <a href="{{ route('activities.index') }}" class="btn btn-outline">Reset</a></div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>When</th><th>Agent</th><th>Lead</th><th>Type</th><th>Outcome</th><th>Duration</th><th>Notes</th></tr></thead>
            <tbody>
            @forelse($activities as $a)
                <tr>
                    <td><small>{{ $a->occurred_at->format('d M Y') }}<br>{{ $a->occurred_at->format('h:i A') }}</small></td>
                    <td>{{ $a->user?->name }}</td>
                    <td>@if($a->lead)<a href="{{ route('leads.show', $a->lead) }}">{{ $a->lead->display_name }}</a>@else — @endif</td>
                    <td><span class="badge">{{ $a->type_label }}</span> @if($a->is_manual)<br><small title="Entered by the agent">manual</small>@endif</td>
                    <td>@if($a->outcome)<span class="badge badge-{{ $a->outcome }}">{{ $a->outcome_label }}</span>@else — @endif</td>
                    <td>{{ $a->duration_label ?? '—' }}</td>
                    <td><small>{{ \Illuminate\Support\Str::limit($a->description, 140) }}</small></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No activities found. Log calls from a lead's page.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $activities->links() }}
</div>
@endsection
